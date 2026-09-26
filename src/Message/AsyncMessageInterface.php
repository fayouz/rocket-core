<?php

namespace Rocket\Core\Message;

/** Marker: messages implementing it go through the "async" transport (worker), see config/packages/messenger.yaml. */
interface AsyncMessageInterface
{
}
