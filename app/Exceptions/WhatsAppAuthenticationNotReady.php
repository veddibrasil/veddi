<?php

namespace App\Exceptions;

use RuntimeException;

/** Only configuration/template checks before a send may select the temporary fallback. */
class WhatsAppAuthenticationNotReady extends RuntimeException {}
