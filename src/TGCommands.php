<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use WildPHP\Core\Commands\Command;
use WildPHP\Core\Commands\ParameterStrategy;
use WildPHP\Core\Commands\StringParameter;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
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

		TGCommandHandler::fromContainer($container)->registerCommand('command', new Command(
			[$this, 'commandCommand'],
			new ParameterStrategy(1, -1, [
				'string' => new StringParameter()
			], true)
		), ['cmd']);

		TGCommandHandler::fromContainer($container)->registerCommand('me', new Command(
			[$this, 'meCommand'],
			new ParameterStrategy(1, -1, [
				'string' => new StringParameter()
			], true)
		));
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
		$command = $args['string'];

		$msg1 = '[TG] ' . $username . ' issued command: ' . $command;
		$privmsg = new PRIVMSG($channel, $msg1);
		$privmsg->setMessageParameters(['relay_ignore']);
		Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
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
		$string = $args['string'];

		$msg = '[TG] *' . $username . ' ' . $string . '*';
		$privmsg = new PRIVMSG($channel, $msg);
		$privmsg->setMessageParameters(['relay_ignore']);
		Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
	}
}