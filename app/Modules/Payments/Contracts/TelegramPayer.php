<?php

declare(strict_types=1);

namespace App\Modules\Payments\Contracts;

/**
 * An online gateway whose payer is a Telegram account (Rialit takes the payer's Telegram id, and nobody else): never
 * offered to a customer who has none — one signed in to the website by SMS — (Services\PaymentMethods), so they pick
 * another way before anything is ordered. A driver's gateway is of one type whatever its settings
 * (GatewayRegistry::paidByTelegram() asks one made of none).
 */
interface TelegramPayer extends OnlineGateway {}
