<?php

namespace App\Services\Mam;

use RuntimeException;

/** MAM could not be reached, or answered with something that is not its JSON. */
class MamException extends RuntimeException {}
