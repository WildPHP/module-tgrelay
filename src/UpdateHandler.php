<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use unreal4u\TelegramAPI\Abstracts\TelegramTypes;
use unreal4u\TelegramAPI\Telegram\Methods\GetFile;
use unreal4u\TelegramAPI\Telegram\Methods\SendMessage;
use unreal4u\TelegramAPI\Telegram\Types\File;
use unreal4u\TelegramAPI\Telegram\Types\Update;
use WildPHP\Core\Channels\ChannelCollection;
use WildPHP\Core\ComponentContainer;
use WildPHP\Core\Connection\IRCMessages\PRIVMSG;
use WildPHP\Core\Connection\Queue;
use WildPHP\Core\Connection\TextFormatter;
use WildPHP\Core\ContainerTrait;
use WildPHP\Core\EventEmitter;
use WildPHP\Core\Logger\Logger;

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
	 * @var TelegramTypes
	 */
	protected $self;

	/**
	 * UpdateHandler constructor.
	 *
	 * @param ComponentContainer $container
	 * @param ChannelMap $channelMap
	 * @param TelegramTypes $self
	 * @param string $baseURL
	 */
	public function __construct(ComponentContainer $container, ChannelMap $channelMap, TelegramTypes $self, string $baseURL)
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
		$promise = $this->downloadFileForUpdate($update, $telegram, $update->message->audio->file_id);

		$promise->then(function (DownloadedFile $file) use ($update, $channel)
		{
			$msg = $this->formatDownloadMessage($update, $file->getUri(), 'uploaded an audio file');
			$privmsg = new PRIVMSG($channel, $msg);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
		});
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function document(Update $update, TgLog $telegram, string $channel)
	{
		$promise = $this->downloadFileForUpdate($update, $telegram, $update->message->document->file_id);

		$promise->then(function (DownloadedFile $file) use ($update, $channel)
		{
			$msg = $this->formatDownloadMessage($update, $file->getUri(), 'uploaded a document');
			$privmsg = new PRIVMSG($channel, $msg);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
		});
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
		$username = $update->message->from->username;
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
			$originIsBot = $update->message->from->username == $this->self->username;
			if (($replyUsername = Utils::getReplyUsername($update, $originIsBot)))
				$message = '@' . TextFormatter::consistentStringColor($replyUsername) . ': ' . $message;

			$message = '[TG] <' . TextFormatter::consistentStringColor(Utils::getSender($update)) . '> ' . $message;

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
		$file_id = end($update->message->photo)->file_id;
		$promise = $this->downloadFileForUpdate($update, $telegram, $file_id);

		$promise->then(function (DownloadedFile $file) use ($update, $channel)
		{
			$msg = $this->formatDownloadMessage($update, $file->getUri(), 'uploaded a picture');
			$privmsg = new PRIVMSG($channel, $msg);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
		});
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function sticker(Update $update, TgLog $telegram, string $channel)
	{
		$promise = $this->downloadFileForUpdate($update, $telegram, $update->message->sticker->file_id);

		$promise->then(function (DownloadedFile $file) use ($update, $channel)
		{
			$msg = $this->formatDownloadMessage($update, $file->getUri(), 'sent a sticker to the group');
			$privmsg = new PRIVMSG($channel, $msg);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
		});
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function video(Update $update, TgLog $telegram, string $channel)
	{
		$promise = $this->downloadFileForUpdate($update, $telegram, $update->message->video->file_id);

		$promise->then(function (DownloadedFile $file) use ($update, $channel)
		{
			$msg = $this->formatDownloadMessage($update, $file->getUri(), 'uploaded a video');
			$privmsg = new PRIVMSG($channel, $msg);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
		});
	}

	/**
	 * @param Update $update
	 * @param TgLog $telegram
	 * @param string $channel
	 */
	public function voice(Update $update, TgLog $telegram, string $channel)
	{
		$promise = $this->downloadFileForUpdate($update, $telegram, $update->message->voice->file_id);

		$promise->then(function (DownloadedFile $file) use ($update, $channel)
		{
			$msg = $this->formatDownloadMessage($update, $file->getUri(), 'sent a voice message');
			$privmsg = new PRIVMSG($channel, $msg);
			$privmsg->setMessageParameters(['relay_ignore']);
			Queue::fromContainer($this->getContainer())->insertMessage($privmsg);
		});
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
	 * @param $file_id
	 *
	 * @return \React\Promise\PromiseInterface
	 */
	public function downloadFileForUpdate(Update $update, TgLog $telegram, $file_id)
	{
		$chat_id = $update->message->chat->id;
		$basePath = $telegram->makeFileStructure($chat_id);

		$getFile = new GetFile();
		$getFile->file_id = $file_id;

		/** @var File $file */
		$file = $telegram
			->performApiRequest($getFile);
		$promise = $telegram->downloadFileAsync($file);

		$promise->then(function (DownloadedFile $file) use ($update, $basePath, $chat_id)
		{
			$fullPath = $basePath . '/' . $file->getFile()->file_path;

			if (!@touch($fullPath) || !@file_put_contents($fullPath, $file->getBody()))
				throw new DownloadException();

			$uri = urlencode($this->baseURL . '/' . sha1($chat_id) . '/' . $file->getFile()->file_path);

			$file->setPath($fullPath);
			$file->setUri($uri);

			return $file;
		});

		return $promise;
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
	 * @param string $url
	 * @param string $fileSpecificMessage
	 *
	 * @return string
	 */
	protected function formatDownloadMessage(Update $update, string $url, string $fileSpecificMessage)
	{
		$sender = TextFormatter::consistentStringColor(Utils::getSender($update));
		$originIsBot = $update->message->from->username == $this->self->username;
		$reply = TextFormatter::consistentStringColor(Utils::getReplyUsername($update, $originIsBot) ?? '');
		$caption = TextFormatter::italic(Utils::getCaption($update));

		$msg = '[TG] ';

		if (!empty($reply))
			$msg .= '@' . $reply . ': ';

		$msg .= $sender . ' ' . $fileSpecificMessage . ': ' . urlencode($url);

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