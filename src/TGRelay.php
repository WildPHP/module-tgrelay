<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use unreal4u\TelegramAPI\Abstracts\TelegramTypes;
use unreal4u\TelegramAPI\Telegram\Methods\GetMe;
use unreal4u\TelegramAPI\Telegram\Methods\GetUpdates;
use unreal4u\TelegramAPI\Telegram\Types\Custom\UpdatesArray;
use unreal4u\TelegramAPI\Telegram\Types\Update;
use ValidationClosures\Types;
use WildPHP\Core\Commands\Command;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Configuration\Configuration;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Logger\Logger;
use WildPHP\Core\Modules\BaseModule;
use Yoshi2889\Collections\Collection;
use Yoshi2889\Tasks\CallbackTask;
use Yoshi2889\Tasks\RepeatableTask;
use Yoshi2889\Tasks\TaskController;

class TGRelay extends BaseModule
{
	use ContainerTrait;

	/**
	 * @var TgLog
	 */
	protected $botObject = null;

	/**
	 * @var TelegramTypes
	 */
	protected $self;

	/**
	 * @var int
	 */
	protected $lastUpdateID = 0;

	/**
	 * @var string
	 */
	protected $baseURI = '';

	/**
	 * @var TaskController
	 */
	protected $taskController;

	/**
	 * TGRelay constructor.
	 *
	 * @param ComponentContainer $container
	 */
	public function __construct(ComponentContainer $container)
	{
		$this->setContainer($container);
		$channelMap = Configuration::fromContainer($container)['telegram']['channels'];
		$botID = Configuration::fromContainer($container)['telegram']['botID'];
		$baseURI = Configuration::fromContainer($container)['telegram']['uri'];
		$port = Configuration::fromContainer($this->getContainer())['telegram']['port'];
		$listenOn = Configuration::fromContainer($this->getContainer())['telegram']['listenOn'];
		$this->baseURI = $baseURI;

		$this->botObject = new TgLog($botID, $container->getLoop());
		$this->self = $this->botObject->performApiRequest(new GetMe());
		$container->add($this->botObject);

		$channelMap = $this->setupChannelMap($channelMap);
		new UpdateHandler($container, $channelMap, $this->self, $baseURI);
		new IrcMessageHandler($container, $this->botObject, $channelMap);
		new FileServer($this->getContainer(), $port, $listenOn);

		$commandHandler = new TGCommandHandler($container, new Collection(Types::instanceof(Command::class)));
		$container->add($commandHandler);
		new TGCommands($container);

		$this->taskController = new TaskController($container->getLoop());
		$callbackTask = new CallbackTask([$this, 'fetchTelegramMessages'], 0, [$container]);
		$task = new RepeatableTask($callbackTask, 1);
		$this->taskController->add($task);

		EventEmitter::fromContainer($container)
			->on('wildphp.init-modules.after', function () use ($commandHandler, $container)
			{
				// Emit an event to let other modules know that commands can be added.
				EventEmitter::fromContainer($container)
					->emit('telegram.commands.add', [$commandHandler]);
			});
	}

	/**
	 * @param array $channelMap
	 *
	 * @return ChannelMap
	 */
	public function setupChannelMap(array $channelMap)
	{
		$collection = new ChannelMap();

		if (!empty($channelMap))
			foreach ($channelMap as $chatID => $channel)
			{
				$linkObject = new TelegramLink();
				$linkObject->setChatID($chatID);
				$linkObject->setChannel($channel);
				$collection->append($linkObject);
			}

		return $collection;
	}

	/**
	 * @param ComponentContainer $container
	 */
	public function fetchTelegramMessages(ComponentContainer $container)
	{
		$tgLog = $this->getBotObject();
		$getUpdates = new GetUpdates();
		$getUpdates->offset = $this->getLastUpdateID();

		try
		{
			/** @var UpdatesArray $updates */
			$updates = $tgLog->performApiRequest($getUpdates);

			if (empty($updates->data))
				return;

			$lastUpdateID = 0;
			/** @var Update $update */
			foreach ($updates->traverseObject() as $update)
			{
				EventEmitter::fromContainer($container)
					->emit('telegram.msg.in', [$update, $tgLog]);

				Logger::fromContainer($container)
					->debug('[TG] Update received', [
						'id' => $update->update_id
					]);

				$lastUpdateID = $update->update_id;
			}

			$this->setLastUpdateID($lastUpdateID + 1);
		}
		catch (\Exception $e)
		{
			return;
		}
	}

	/**
	 * @return TgLog
	 */
	public function getBotObject(): TgLog
	{
		return $this->botObject;
	}

	/**
	 * @return int
	 */
	public function getLastUpdateID(): int
	{
		return $this->lastUpdateID;
	}

	/**
	 * @param int $lastUpdateID
	 */
	public function setLastUpdateID(int $lastUpdateID)
	{
		$this->lastUpdateID = $lastUpdateID;
	}

	/**
	 * @return string
	 */
	public static function getSupportedVersionConstraint(): string
	{
		return '^3.0.0';
	}
}