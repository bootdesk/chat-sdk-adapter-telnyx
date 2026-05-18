<?php

declare(strict_types=1);

use BootDesk\ChatSDK\Core\Support\AdapterRegistry;
use BootDesk\ChatSDK\Telnyx\TelnyxAdapter;

AdapterRegistry::register('telnyx', TelnyxAdapter::class);
