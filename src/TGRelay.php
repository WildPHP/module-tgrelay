<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use unreal4u\TelegramAPI\Abstracts\TelegramTypes;
use unreal4u\TelegramAPI\HttpClientRequestHandler;
use unreal4u\TelegramAPI\Telegram\Methods\GetMe;
use unreal4u\TelegramAPI\Telegram\Methods\GetUpdates;
use unreal4u\TelegramAPI\Telegram\Types\Custom\UpdatesArray;
use unreal4u\TelegramAPI\Telegram\Types\Update;
use unreal4u\TelegramAPI\Telegram\Types\User;
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
		$telegramConfig = Configuration::fromContainer($container)['telegram'] ?? [];
		
		if (empty($telegramConfig))
		{
			Logger::fromContainer($container)->warning('Unable to initialize Telegram module; make sure you have included all configuration ' .
			'options in your config.neon');
			return;
		}
		
		$channelMap = $telegramConfig['channels'];
		$botID = $telegramConfig['botID'];
		$this->baseURI = $telegramConfig['uri'];
		$port = $telegramConfig['port'];
		$listenOn = $telegramConfig['listenOn'];

		$httpClientHandler = new HttpClientRequestHandler($container->getLoop());
		$this->botObject = new TgLog($botID, $httpClientHandler);

		$commandHandler = new TGCommandHandler($container, new Collection(Types::instanceof(Command::class)));
		$container->add($commandHandler);
		new TGCommands($container);

		EventEmitter::fromContainer($container)
			->on('wildphp.init-modules.after', function () use ($commandHandler, $container) {
				// Emit an event to let other modules know that commands can be added.
				EventEmitter::fromContainer($container)
					->emit('telegram.commands.add', [$commandHandler]);
			});

		// Test if the connection worked. Added bonus of getting some info about ourselves.
		$promise = $this->botObject->performApiRequest(new GetMe());
		$promise->then(
			function (User $result) use ($channelMap, $container, $port, $listenOn) {
				$container->add($this->botObject);

				$this->self = $result;
				$channelMapObject = $this->setupChannelMap($channelMap);
				new UpdateHandler($container, $channelMapObject, $this->self, $this->baseURI);
				new IrcMessageHandler($container, $this->botObject, $channelMapObject);
				new FileServer($this->getContainer(), $port, $listenOn);

				$this->taskController = new TaskController($container->getLoop());
				$this->taskController->add(
					new RepeatableTask(
						new CallbackTask([$this, 'fetchTelegramMessages'], 0, [$container]),
						5
					)
				);
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

		$promise = $tgLog->performApiRequest($getUpdates);

		$promise->then(function (UpdatesArray $updates) use ($container, $tgLog) {
			if (empty($updates->data))
				return;
			
			/** @var Update $update */
			foreach ($updates->getIterator() as $update)
			{
				$this->setLastUpdateID($update->update_id + 1);
				
				EventEmitter::fromContainer($container)
					->emit('telegram.msg.in', [$update, $tgLog]);

				Logger::fromContainer($container)
					->debug('[TG] Update received', [
						'id' => $update->update_id
					]);
			}
		});
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