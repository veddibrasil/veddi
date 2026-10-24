<?php

namespace App\Exceptions;

use DomainException;

/** Regra de cobrança da assinatura impediu a operação antes de cobrar. A mensagem é segura para o cliente. */
class SubscriptionBillingException extends DomainException {}
