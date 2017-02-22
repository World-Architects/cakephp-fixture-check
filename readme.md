# CakePHP Fixture Check Plugin

A shell that will compare fixtures against live DB tables to make it easy to spot differences.

## Requirements

* CakePHP ^3.4

## Installation
```
composer require --dev psa/cakephp-fixture-check
```

And in your `bootstrap.php` (or better yet `bootstrap_cli.php`):
```
Plugin::load('Psa/FixtureCheck');
```

## Usage
```
bin/cake FixtureCheck
```

To run for specific fixtures and tables, use
```
bin/cake FixtureCheck -f Fixture1,Fixture2
```


## License

Copyright 2015 - 2016 PSA Publishers

Licensed under the [MIT](http://www.opensource.org/licenses/mit-license.php) License. Redistributions of the source code included in this repository must retain the copyright notice found in each file.
