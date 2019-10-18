# PhergieUserWatch

[Phergie](http://github.com/phergie/phergie-irc-bot-react/) plugin to watch joins, parts and quits of users. You can also whois users easily.

## About

This plugin was originally written to keep the growing amount of monitoring-bots in the Rizon network out of channels.
However you can do what you want with the callbacks, it'll require a bit of PHP knowledge though.

## Install

Use the command below to install it with [Composer](http://getcomposer.org/) to the current `$PWD`.

```
composer require hashworks/phergie-user-watch-plugin
```

See Phergie documentation for more information on
[installing and enabling plugins](https://github.com/phergie/phergie-irc-bot-react/wiki/Usage#plugins).

## Configuration Examples

The configuration allows you to set up to 3 callbacks: joinCallback, partCallback and quitCallback. Below are some examples.

```php
// Simple example, give voice to every user who joins the channel.
new \hashworks\Phergie\Plugin\UserWatch\Plugin(array(
                'joinCallback' => function(\hashworks\Phergie\Plugin\UserWatch\User $user) {
                    $user->setChannelMode('+v', $user->getNick());
                }
        )
)
```

```php
// Kick everyone who isn't using a secure connection.
new \hashworks\Phergie\Plugin\UserWatch\Plugin(array(
                'joinCallback' => function(\hashworks\Phergie\Plugin\UserWatch\User $user) {
                    $user->queueWhois(function() use($user) {
                        if (!$user->hasSecureConnection()) {
                            $user->kick('This channel requires a secure connection.');
                        }
                    });
                }
        )
)
```

```php
// This is kinda how I use it. Kickban every user who is in 13 channels or more. Ban based on nick and username, replace numbers with question marks.
new \hashworks\Phergie\Plugin\UserWatch\Plugin(array(
                'joinCallback' => function(\hashworks\Phergie\Plugin\UserWatch\User $user) {
                    $user->queueWhois(function() use($user) {
                        if (count($user->getChannels()) >= 13) {
                            $banMask = preg_replace_callback('/^(?<nick>.+?)(?<nicknumbers>[0-9]{0,})!(?<username>.+?)(?<usernumbers>[0-9]{0,})@.+$/', function ($matches) {
                                return $matches['nick'] . str_replace(array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9), '?', $matches['nicknumbers']) . '!' .
                                $matches['username'] . str_replace(array(0, 1, 2, 3, 4, 5, 6, 7, 8, 9), '?', $matches['usernumbers']) . '@*';
                            }, $user->getNick() . '!' . $user->getUsername() . '@' . $user->getHost());
                            if (!empty($banMask)) {
                                $user->setChannelMode('+b', $banMask);
                                $user->kick('You have been kicked automatically. Please contact hashworks to file a complaint.');
                                $user->privmsgUser('You have been banned automatically from ' . $user->getEvent()->getSource() . '. . Please contact hashworks to file a complaint.');
                            }
                        }
                    });
                }
        )
)
```
