[![StyleCI](https://github.styleci.io/repos/179174116/shield?branch=master)](https://github.styleci.io/repos/179174116)

# Codepad

![example deployment](resources/assets/img/codepad.png)

## Table of Contents

* [System Requirements](#system-requirements)
  * [Optional Requirements](#optional-requirements)
* [Quick Install](#quick-install)
* [Application Structure](#application-structure)
* [Downloading & Compiling](#downloading--compiling)
* [Creating the Chroot Environment](#creating-the-chroot-environment)
* [Enabling the Worker](#enabling-the-worker)
  * [How it Works](#how-it-works)
* [Preparing the UI](#preparing-the-ui)
* [Deployment](#deployment-help)

## System Requirements

- build-essential 
- pkg-config
- libcurl4-openssl-dev
- libxml2-dev
- libtidy-dev

### Optional Requirements

- bison
- re2c

## Quick install

Compile PHP:

```bash
$ php cli/install --version="<(string)version>"
```

Create chroot environment:
```bash
$ sudo php cli/chroot --root="<(string)chrootpath>" --version="<(string)version>" --first-run="<(bool)first-run>"
```

Instead of passing options explicitly via the command line, you can also set them via environment variables in `.env`:
```bash
CHROOT_ROOT="/opt/phpchroot"
CHROOT_DEVICES="bin,dev,etc,lib,lib64,usr"
CHROOT_PHP_VERSION="7.1.30"
```

```bash
$ sudo php cli/build
```

## Application Structure
```
.
├── cli                     # Console commands
├── config                  # Application config files
│   ├── .env                # Environment variables
│   ├── assets.json         # Front-end assets to compile
│   ├── bootstrap.php       # Application bootstrapper
│   ├── controllers.php     # Place to register application controllers
│   ├── dependencies.php    # Place to register application dependencies
│   └── routes.php          # Place to register application routes
├── node_modules            # Reserved for NPM
├── public                  # Entry, web and cache files
├── resources               # Application resources
│   ├── assets              # Raw, un-compiled assets such as media, SASS and JavaScript
│   ├── views               # View templates (twig)
├── src                     # Appliation source code
│   ├── Console             # Console command classes
│   ├── Frontend            # Configuration files
│       └── Controllers     # Frontend controllers
├── vendor                  # Reserved for Composer
├── composer.json           # Composer dependencies
├── gulpfile.esm.js         # Gulp configuration
├── LICENSE                 # The license
├── package.json            # Yarn dependencies
└── README.md               # This file
```

## Downloading & Compiling

```php
<?php

use Versyx\Codepad\Console\Compiler;
use Versyx\Codepad\Console\Downloader;

require __DIR__ . '/../config/bootstrap.php';

run($app['downloader'], $app['compiler'], getopt('', ['version:']));

function run(Downloader $downloader, Compiler $compiler, array $opts)
{
    if(!$version = env("CHROOT_PHP_VERSION")) {
        $version = $opts['version'] ?? error('You must specify a version.');
    }
    
    try {
        $php = $downloader->setVersion($version)->download();
        $compiler->compile($php->getVersion(), $php->getTarget());
    } catch (\Exception $e) {
        echo $e->getMessage();
        exit;
    }
}

```

## Creating the Chroot Environment

```php
#!/usr/bin/env php

<?php

use Versyx\Codepad\Console\ChrootManager;

require __DIR__ . '/../config/bootstrap.php';

run($app['chroot-manager'], getopt('', ['root::', 'version:', 'first-run::']));

function run(ChrootManager $cm, array $opts)
{
    if(!$root = env("CHROOT_ROOT")) {
        $root = $opts['root'] ?? error('You must specify a root path.');
    }

    $cm->setRoot($root);

    if(env("CHROOT_DEVICES")) {
        $cm->setDevices(explode(',', env("CHROOT_DEVICES")));
    } else {
        $cm->setDevices(['bin','dev','etc','lib','lib64','usr']);
    }

    if(!$version = env("CHROOT_PHP_VERSION")) {
        $version = $opts['version'] ?? error('You must specify a version.');
    }

    $php = '/php-' . $version;

    $cm->buildChroot($version);

    if(isset($opts['first-run'])) {
        $cm->setPermissions(0711);
        $cm->setOwnership('root', 'root');
        $cm->mountAll();
    }
    
    $cm->mkChrootDir($php);
    if(file_exists('/var/www/codepad' . $php)) {
        $cm->mount('/var/www/codepad' . $php, $cm->getRoot() . $php, 'bind', 'ro');
    } else {
        $cm->mount('/tmp' . $php, $cm->getRoot() . $php, 'bind', 'ro');
    }
}
```

## Enabling the Worker

You'll need to allow www-data to run the worker script as a privileged user, add entries for each compiled version to
 `/etc/sudoers` like so:


ubuntu:
```bash
www-data ALL =(ALL) NOPASSWD: /opt/phpchroot/php-7.3.6/bin/php /var/www/codepad/public/http/worker.php 7.3.6
www-data ALL =(ALL) NOPASSWD: /opt/phpchroot/php-7.0.33/bin/php /var/www/codepad/public/http/worker.php 7.0.33
```

centos:
```bash
apache ALL =(ALL) NOPASSWD: /opt/phpchroot/php-7.3.6/bin/php /var/www/codepad/public/http/worker.php 7.3.6
apache ALL =(ALL) NOPASSWD: /opt/phpchroot/php-7.0.33/bin/php /var/www/codepad/public/http/worker.php 7.0.33
```

This will restrict `www-data`'s|`apache`'s sudo privileges to only running the worker.

### How it Works

The PHP code and version is base64 encoded and submitted to `http/manager.php`, the manager then 
base64 decodes the data and runs a check on the code input against disabled functions, if the check
comes back clean, a new process is created with the following stream resources:

```php
$proc = proc_open("sudo /opt/phpchroot/php-$ver/bin/php /var/www/" . env("APP_NAME") . "/public/http/worker.php $ver", [
    0 => ["pipe", "rb"],
    1 => ["pipe", "wb"],
    2 => ["pipe", "wb"]
], $pipes);
```

The PHP code is passed to `http/worker.php` from the manager via STDIN, the worker then creates a temporary file in
`/opt/phpchroot`, sets its permissions to `0444` and then executes the file using the selected PHP version
instance, which is chrooted to `/opt/phpchroot` as user `nobody`. If the code takes longer than five seconds to execute, 
the process will terminate.

```php
$starttime = microtime(true);
$unused = [];
$ph = proc_open('chroot --userspec=nobody /opt/phpchroot /php-' . $argv[1] .'/bin/php ' . escapeshellarg(basename($file)), $unused, $unused);
$terminated = false;
while (($status = proc_get_status($ph)) ['running']) {
    usleep(100 * 1000);
    if (!$terminated && microtime(true) - $starttime > MAX_RUNTIME_SECONDS) {
        $terminated = true;
        echo 'max runtime reached (' . MAX_RUNTIME_SECONDS . ' seconds), terminating...';
        pKill($status['pid']);
    }
}

proc_close($ph);
```

## Preparing the UI

### Build assets

Codepad uses NPM to manage front-end dependencies such as Bootstrap and gulp to build and minify raw assets such as SCSS, JS and other media. The existing tasks in gulpfile.esm.js shouldn’t need to be touched, as all paths to assets are configured via `config/assets.json`:

```json
{
    "vendor": {
        "styles": [
            "node_modules/bootstrap/scss/bootstrap.scss"
        ],
        "css": "bundle.min.css",
        "scripts": [
            "node_modules/axios/dist/axios.min.js",
            "node_modules/bootstrap/dist/js/bootstrap.min.js"
        ],
        "js": "bundle.min.js"
    },
    "app": {
        "styles": [
            "resources/assets/scss/app.scss"
        ],
        "css": "app.min.css",
        "scripts": [
            "resources/assets/js/app.js"
        ],
        "js": "app.min.js"
    },
    "out": "./public"
}
```

Example gulp tasks:
```js
import config from './config/assets';
import { src, dest, series } from 'gulp';
import plugins from 'gulp-load-plugins';

const plugin = plugins();

function styles(cb) {
    src(config.vendor.styles)
        .pipe(plugin.sass({outputStyle: 'compressed'}))
        .pipe(plugin.concat(config.vendor.css))
        .pipe(dest(config.out + '/css'));

    src(config.app.styles)
        .pipe(plugin.sass({outputStyle: 'compressed'}))
        .pipe(plugin.concat(config.app.css))
        .pipe(dest(config.out + '/css'));

    cb();
}

function scripts(cb) {
    
    src(config.vendor.scripts)
        .pipe(plugin.sourcemaps.init())
        .pipe(plugin.concat(config.vendor.js))
        .pipe(plugin.sourcemaps.write('./'))
        .pipe(dest(config.out + '/js'));

    src(config.app.scripts)
        .pipe(plugin.rename(config.app.js))
        .pipe(plugin.sourcemaps.init())
        .pipe(plugin.uglifyEs.default())
        .pipe(plugin.sourcemaps.write('./'))
        .pipe(dest(config.out + '/js'));

    cb();
}

exports.styles  = styles;
exports.scripts = scripts;
exports.build   = series(styles, scripts);
```

To install assets, run:
```bash
$ npm install
```

To compile assets, run:
```bash
$ gulp build
```

Your application should now be ready to view!

## Deployment Help

Download [this vagrant box](https://app.vagrantup.com/raekw0n/boxes/ubuntu16):

```bash
$ vagrant box add raekw0n/ubuntu16
$ vagrant init raekw0n/ubuntu16
```

Edit your Vagrantfile to create a symbolic link between the directory containing your projects and `/var/www`:
```ruby
Vagrant.configure("2") do |config|
    config.vm.box = "raekw0n/ubuntu16"
    config.vm.network "forwarded_port", guest: 80, host: 8080
    ...
    config.vm.synced_folder "/host/path/to/projects", "/var/www"
end
```

Launch the VM:
```bash
$ vagrant up
```

Configure the following variables in - `cli\deploy`:
```bash
project="your-project-name"

install_path="/var/www/your-project-name"
chroot_path="/path/to/chroot"

site_domain="your-domain.local"

declare -a versions=(
    "7.0.33"
    "7.1.30"
    "..."
)
```

SSH into it and run the `cli/deploy` provisioning script:
```bash
$ cd /var/www/codepad
$ ./cli/deploy
```
