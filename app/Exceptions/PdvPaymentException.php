<?php

namespace App\Exceptions;

use DomainException;

/** Regra de negócio do PDV violada (ex.: dinheiro recebido menor que o total). A mensagem é segura para o operador. */
class PdvPaymentException extends DomainException {}
