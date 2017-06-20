<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;


use unreal4u\TelegramAPI\TgLog;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\Logger\Logger;

class TGCommands
{
	use ContainerTrait;

	/**
	 * TGCommands constructor.
	 *
	 * @param ComponentContainer $container
	 */
	public function __construct(ComponentContainer $container)
	{
		$this->setContainer($container);
		$events = [
			'command' => 'telegram.command.command',
			'me' => 'telegram.command.me',
		];

		foreach ($events as $callback => $event)
		{
			TGCommandHandler::fromContainer($container)->registerCommand($callback, [$this, $callback . 'Command'], null, 0, -1);
		}
	}

	/**
	 * @param TgLog $telegram
	 * @param $chat_id
	 * @param array $args
	 * @param string $channel
	 * @param string $username
	 */
	public function commandCommand(TgLog $telegram, $chat_id, array $args, string $channel, string $username)
	{
		Logger::fromContainer($this->getContainer())->debug('Command command called');
		$command = implode(' ', $args);

		$msg1 = '[TG] ' . $username . ' issued command: ' . $command;
		Queue::fromContainer($this->getContainer())->privmsg($channel, $msg1);
		Queue::fromContainer($this->getContainer())->privmsg($channel, $command);
	}

	/**
	 * @param TgLog $telegram
	 * @param $chat_id
	 * @param array $args
	 * @param string $channel
	 * @param string $username
	 */
	public function meCommand(TgLog $telegram, $chat_id, array $args, string $channel, string $username)
	{
		$command = implode(' ', $args);

		$msg = '[TG] *' . $username . ' ' . $command . '*';
		Queue::fromContainer($this->getContainer())->privmsg($channel, $msg);
	}
}