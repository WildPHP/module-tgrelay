<?php
/**
 * Copyright 2017 The WildPHP Team
 *
 * You should have received a copy of the MIT license with the project.
 * See the LICENSE file for more information.
 */

namespace WildPHP\Modules\TGRelay;

use WildPHP\Core\ComponentTrait;

/**
 * Class TgLog
 * @package WildPHP\Modules\TGRelay
 *
 * Simple wrapper around the TgLog object, to allow it to sit in a container.
 */
class TgLog extends \unreal4u\TelegramAPI\TgLog
{
	use ComponentTrait;
}