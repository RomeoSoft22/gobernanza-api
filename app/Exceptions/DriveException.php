<?php

namespace App\Exceptions;

use RuntimeException;

/** Error de negocio en la integración con Google Drive (se responde como 422). */
class DriveException extends RuntimeException {}
