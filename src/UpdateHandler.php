<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use unreal4u\TelegramAPI\InternalFunctionality\TelegramDocument;
use unreal4u\TelegramAPI\Telegram\Methods\GetFile;
use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use unreal4u\TelegramAPI\Telegram\Types\File;
use unreal4u\TelegramAPI\Telegram\Types\Update;
use unreal4u\TelegramAPI\Telegram\Types\User;
use WildPHP\Core\Channels\ChannelCollection;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\Connection\TextFormatter;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Logger\Logger;
use WildPHP\Core\Modules\ModuleFactory;
use WildPHP\Modules\Factoids\Factoid;
use WildPHP\Modules\Factoids\Factoids;

class UpdateHandler
{
	use ContainerTrait;

	/**
	 * @var string
	 */
	protected $baseURL;

	/**
	 * @var ChannelMap
	 */
	protected $channelMap;

	/**
	 * @var User
	 */
	protected $self;

	/**
	 * UpdateHandler constructor.
	 *
	 * @param ComponentContainer $container
	 * @param ChannelMap $channelMap
	 * @param User $self
	 * @param string $baseURL
	 */
	public function __construct(ComponentContainer $container, ChannelMap $channelMap, User $self, string $baseURL)
	{
		$this->baseURL = $baseURL;
		$this->setContainer($container);
		$this->channelMap = $channelMap;
		$this->self = $self;

		EventEmitter::fromContainer($container)
			->on('telegram.msg.in', [$this, 'routeUpdate']);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function audio(Update $update, TgLog $telegram, string $channel)
	{
		$this->genericDownloadableFile($update, $telegram, $channel, $update->message->audio->file_id, 'uploaded an audio file');
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function document(Update $update, TgLog $telegram, string $channel)
	{
		$this->genericDownloadableFile($update, $telegram, $channel, $update->message->document->file_id, 'uploaded a document');
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function entities(Update $update, TgLog $telegram, string $channel)
	{
		$text = $update->message->text;
		$chat_id = $update->message->chat->id;
		$username = Utils::getUsernameForUser($update->message->from);
		$coloredUsername = TextFormatter::consistentStringColor($username);

		$result = TGCommandHandler::fromContainer($this->getContainer())
			->parseAndRunTGCommand($text, $telegram, $chat_id, $channel, $username, $coloredUsername);

		if (!$result)
			$this->message($update, $telegram, $channel);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function message(Update $update, TgLog $telegram, string $channel)
	{
		if (empty($channel))
			return;

		$message = $update->message->text;
		$messages = array_filter(explode("\n", str_replace("\r", "\n", $message)));

		if (count($messages) > 10)
		{
			$sendMessage = new SendMessage();
			$sendMessage->text = 'Cut off message to IRC; too many lines (max. 10 supported)';
			$sendMessage->chat_id = $update->message->chat->id;
			$telegram->performApiRequest($sendMessage);
			$messages = array_chunk($messages, 10)[0];
			$messages[] = TextFormatter::italic('...(cut off more messages)...');
		}

		foreach ($messages as $message)
		{
			$originIsBot = empty($update->message->reply_to_message) ? false :
				$update->message->reply_to_message->from->username == $this->self->username;
			if (($replyUsername = Utils::getReplyUsername($update, $originIsBot)))
				$message = '@' . TextFormatter::consistentStringColor($replyUsername) . ': ' . $message;

			$message = '[TG] <' . TextFormatter::consistentStringColor(Utils::getUsernameForUser($update->message->from)) . '> ' . $message;

			$privmsg = new PRIVMSG($channel, $message);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())
				->insertMessage($privmsg);
		}
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function photo(Update $update, TgLog $telegram, string $channel)
	{
		// Get the largest size.
		$photoSizeArray = (array) $update->message->photo->getIterator();
		$file_id = end($photoSizeArray)->file_id;
		$this->genericDownloadableFile($update, $telegram, $channel, $file_id, 'uploaded a picture');
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function sticker(Update $update, TgLog $telegram, string $channel)
	{
		$this->genericDownloadableFile($update, $telegram, $channel, $update->message->sticker->file_id, 'sent a sticker to the group');
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function video(Update $update, TgLog $telegram, string $channel)
	{
		$this->genericDownloadableFile($update, $telegram, $channel, $update->message->video->file_id, 'uploaded a video');
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function voice(Update $update, TgLog $telegram, string $channel)
	{
		$this->genericDownloadableFile($update, $telegram, $channel, $update->message->voice->file_id, 'sent a voice message');
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function contact(Update $update, TgLog $telegram, string $channel)
	{
		$this->unsupported($update, $telegram, $channel);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function game(Update $update, TgLog $telegram, string $channel)
	{
		$this->unsupported($update, $telegram, $channel);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function location(Update $update, TgLog $telegram, string $channel)
	{
		$this->unsupported($update, $telegram, $channel);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function venue(Update $update, TgLog $telegram, string $channel)
	{
		$this->unsupported($update, $telegram, $channel);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function invoice(Update $update, TgLog $telegram, string $channel)
	{
		$this->unsupported($update, $telegram, $channel);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function new_chat_members(Update $update, TgLog $telegram, string $channel)
	{
		$newMembers = $update->message->new_chat_members;
		$from = Utils::getUsernameForUser($update->message->from);

		/** @var User $member */
		foreach ($newMembers as $member)
		{
			$nickname = Utils::getUsernameForUser($member);
			$msg = '[TG] ' . TextFormatter::consistentStringColor($nickname) . ' joined the Telegram group (added by ' . TextFormatter::consistentStringColor($from) . '), say hello!';

			$privmsg = new PRIVMSG($channel, $msg);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())->insertMessage($privmsg);

			// Send a welcome message.
			if (ModuleFactory::fromContainer($this->getContainer())->isModuleLoaded(Factoids::class))
			{
				/** @var Factoids $factoidsModule */
				$factoidsModule = ModuleFactory::fromContainer($this->getContainer())->getModuleInstance(Factoids::class);

				/** @var Factoid $factoid */
				if (!($factoid = $factoidsModule->getFactoid('tg_welcome', $channel)))
					return;

				$msg = $factoid->getContents();

				// TODO: Make generic escape function
				$nickname = strtr($nickname, [
					'*' => '\*',
					'_' => '\_',
					'`' => '\`'
				]);
				$msg = str_ireplace('$nick', $nickname, $msg);

				$sendMessage = new SendMessage();
				$sendMessage->chat_id = $update->message->chat->id;
				$sendMessage->text = $msg;
				$sendMessage->reply_to_message_id = $update->message->message_id;
				$sendMessage->parse_mode = 'Markdown';
				$sendMessage->disable_web_page_preview = true;
				$telegram->performApiRequest($sendMessage);
			}
		}
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function left_chat_member(Update $update, TgLog $telegram, string $channel)
	{
		$member = $update->message->left_chat_member;
		$nickname = $member->username ?? trim($member->first_name . ' ' . $member->last_name);
		$msg = '[TG] ' . TextFormatter::consistentStringColor($nickname) . ' left the Telegram group.';

		$privmsg = new PRIVMSG($channel, $msg);
		$privmsg->setMessageParameters(['relay_ignore']);
		Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param $file_id
	 *
	 * @return PromiseInterface
	 */
	public function downloadFileForUpdate(Update $update, TgLog $telegram, $file_id)
	{
		$chat_id = $update->message->chat->id;
		$basePath = $telegram->makeFileStructure($chat_id);

		$getFile = new GetFile();
		$getFile->file_id = $file_id;
		
		Logger::fromContainer($this->getContainer())->debug('[TG] Attempting to download file...', ['id' => $file_id]);

		$deferred = new Deferred();

		$promise = $telegram->performApiRequest($getFile);
		
		$promise->then(function (File $file) use ($telegram, $basePath, $deferred, $update, $chat_id)
		{
			Logger::fromContainer($this->getContainer())->debug('[TG] File requested, initiating download...', ['id' => $file->file_id]);
			$filePath = $file->file_path;
			$fullPath = $basePath . '/' . $file->file_path;
			$promise = $telegram->downloadFile($file);

			$promise->then(function (TelegramDocument $file) use ($update, $basePath, $chat_id, $fullPath, $filePath, $deferred)
			{
				if (!touch($fullPath) || !file_put_contents($fullPath, $file->contents))
					throw new DownloadException();

				$uri = $this->baseURL . '/' . sha1($chat_id) . '/' . str_replace('%2F', '/', urlencode($filePath));

				$file = new ExtendedTelegramDocument($file, $uri, $fullPath);
				$deferred->resolve($file);
				Logger::fromContainer($this->getContainer())->debug('[TG] Download complete!', ['id' => $file->getPath()]);
			},
			function (\Exception $e) use ($deferred)
			{
				$deferred->reject(new DownloadException('An error occurred while downloading', 0, $e));
			});
		},
		function (\Exception $e)
		{
			Logger::fromContainer($this->getContainer())->debug('[TG] Error while downloading file: ' . $e->getMessage());
		});

		return $deferred->promise();
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function unsupported(Update $update, TgLog $telegram, string $channel)
	{
		if (empty($channel))
			return;

		$sendMessage = new SendMessage();
		$sendMessage->chat_id = $update->message->chat->id;
		$sendMessage->text = 'Unable to relay message to IRC because it is not supported';
		$telegram->performApiRequest($sendMessage);
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 * @param $file_id
	 * @param string $fileSpecificMessage
	 */
	public function genericDownloadableFile(Update $update, TgLog $telegram, string $channel, $file_id, string $fileSpecificMessage)
	{
		$promise = $this->downloadFileForUpdate($update, $telegram, $file_id);

		$promise->then(function (ExtendedTelegramDocument $file) use ($update, $channel, $fileSpecificMessage)
		{
			$msg = $this->formatDownloadMessage($update, $file->getUri(), $fileSpecificMessage);
			$privmsg = new PRIVMSG($channel, $msg);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
		},
		function (\Exception $e)
		{
			Logger::fromContainer($this->getContainer())->debug('[TG] Error while downloading file: ' . $e->getMessage());
		});
		
		$promise->then(null,
		function (\Exception $e)
		{
			Logger::fromContainer($this->getContainer())->debug('[TG] Error while downloading file: ' . $e->getMessage());
		});
	}

	/**
	 * @param Update $update
	 * @param string $url
	 * @param string $fileSpecificMessage
	 *
	 * @return string
	 */
	protected function formatDownloadMessage(Update $update, string $url, string $fileSpecificMessage)
	{
		$sender = TextFormatter::consistentStringColor(Utils::getUsernameForUser($update->message->from));
		$originIsBot = !empty($update->message->reply_to_message) && $update->message->reply_to_message->from->username == $this->self->username;
		$reply = TextFormatter::consistentStringColor(Utils::getReplyUsername($update, $originIsBot) ?? '');
		$caption = Utils::getCaption($update);

		$msg = '[TG] ';

		if (!empty($reply))
			$msg .= '@' . $reply . ': ';

		$msg .= $sender . ' ' . $fileSpecificMessage . ': ' . $url;

		if (!empty($caption))
			$msg .= ' (' . $caption . ')';

		return $msg;
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 */
	public function routeUpdate(Update $update, TgLog $telegram)
	{
		if (empty($update->message))
			return;

		$chat_id = $update->message->chat->id;
		$channel = $this->getChannelMap()
			->findChannelForID($chat_id);

		// Don't bother processing if we aren't in the channel...
		if (!empty($channel) && !ChannelCollection::fromContainer($this->getContainer())->containsChannelName($channel))
			return;

		$type = Utils::getUpdateType($update);

		if (!method_exists($this, $type))
		{
			Logger::fromContainer($this->getContainer())->debug('Message type not implemented!', [
				'type' => $type
			]);
			return;
		}

		$this->$type($update, $telegram, $channel);
	}

	/**
	 * @return ChannelMap
	 */
	public function getChannelMap(): ChannelMap
	{
		return $this->channelMap;
	}
}