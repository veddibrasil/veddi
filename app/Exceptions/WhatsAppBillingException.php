<?php

namespace App\Exceptions;

/** Problema de pagamento na WABA (131042): o restaurante precisa ajustar a forma de pagamento na Meta. */
class WhatsAppBillingException extends WhatsAppApiException {}
