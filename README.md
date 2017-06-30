# Telegram Relay Module
[![Build Status](https://scrutinizer-ci.com/g/WildPHP/module-tgrelay/badges/build.png?b=master)](https://scrutinizer-ci.com/g/WildPHP/module-tgrelay/build-status/master)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/WildPHP/module-tgrelay/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/WildPHP/module-tgrelay/?branch=master)
[![Latest Stable Version](https://poser.pugx.org/wildphp/module-tgrelay/v/stable)](https://packagist.org/packages/wildphp/module-tgrelay)
[![Latest Unstable Version](https://poser.pugx.org/wildphp/module-tgrelay/v/unstable)](https://packagist.org/packages/wildphp/module-tgrelay)
[![Total Downloads](https://poser.pugx.org/wildphp/module-tgrelay/downloads)](https://packagist.org/packages/wildphp/module-tgrelay)

Telegram relay module for WildPHP.

## System Requirements
If your setup can run the main bot, it can run this module as well. For the file server, a system is needed with sufficient disk space to host a very small webserver (will grow over time).

## Installation
To install this module, we will use `composer`:

```composer require wildphp/module-tgrelay```

That will install all required files for the module. In order to activate the module, add the following line to your modules array in `config.neon`:

    - WildPHP\Modules\TGRelay\TGRelay

The bot will run the module the next time it is started.

## Configuration
First setup a Telegram bot. There are many guides on the internet for this.
Add and adjust the following snipped in your `config.neon`:

```neon
telegram:
    port: 9093
    listenOn: '0.0.0.0'
    uri: 'http://localhost:9093'
    botID: 'your bot ID here'
    channels:
        'chat_id': 'irc_channel'
```

## Usage
Link channels in the config. Use the `/command` command to send commands to the channel (to other bots e.g.).

Other modules can add commands to the bot.

## License
This module is licensed under the MIT license. Please see `LICENSE` to read it.
