#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use Web3p\EthereumUtil\Util;

echo "Generating new Ethereum wallet for game server...\n\n";

// Generate a random private key (32 bytes)
$privateKey = bin2hex(random_bytes(32));

// Derive public key and address
$util = new Util();
$publicKey = $util->privateKeyToPublicKey($privateKey);
// publicKeyToAddress already returns the address WITH 0x prefix
$address = $util->publicKeyToAddress($publicKey);

echo "🔑 WALLET GENERATED SUCCESSFULLY\n";
echo "================================\n\n";
echo "Address: " . $address . "\n";
echo "Private Key: 0x" . $privateKey . "\n\n";
echo "⚠️  IMPORTANT INSTRUCTIONS:\n";
echo "1. Save the private key in your .env.local file:\n";
echo "   MONAD_PRIVATE_KEY=0x" . $privateKey . "\n\n";
echo "2. NEVER commit .env.local to git\n\n";
echo "3. Fund this wallet with MONAD tokens for gas fees:\n";
echo "   Send MONAD testnet tokens to: " . $address . "\n\n";
echo "4. Keep the private key SECRET - anyone with it can control the wallet!\n";