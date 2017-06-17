<?php
/**
 * WildPHP - an advanced and easily extensible IRC bot written in PHP
 * Copyright (C) 2017 WildPHP
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace WildPHP\Modules\TGRelay;


use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
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
	 * @param \Telegram $telegram
	 * @param $chat_id
	 * @param array $args
	 * @param string $channel
	 * @param string $username
	 */
	public function commandCommand(\Telegram $telegram, $chat_id, array $args, string $channel, string $username)
	{
		Logger::fromContainer($this->getContainer())->debug('Command command called');
		$command = implode(' ', $args);

		$msg1 = '[TG] ' . $username . ' issued command: ' . $command;
		Queue::fromContainer($this->getContainer())->privmsg($channel, $msg1);
		Queue::fromContainer($this->getContainer())->privmsg($channel, $command);
	}

	/**
	 * @param \Telegram $telegram
	 * @param $chat_id
	 * @param array $args
	 * @param string $channel
	 * @param string $username
	 */
	public function meCommand(\Telegram $telegram, $chat_id, array $args, string $channel, string $username)
	{
		$command = implode(' ', $args);

		$msg = '[TG] *' . $username . ' ' . $command . '*';
		Queue::fromContainer($this->getContainer())->privmsg($channel, $msg);
	}
}