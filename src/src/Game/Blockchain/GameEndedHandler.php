<?php

declare(strict_types=1);

namespace App\Game\Blockchain;

use App\Game\GameLifecycle\GameEnded;
use App\Game\Player\Player;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Telephantast\MessageBus\Handler\Mapping\Handler;
use Web3\Contract;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Web3\Utils;
use Web3\Web3;
use Web3p\EthereumTx\Transaction;
use Web3p\EthereumUtil\Util;

final class GameEndedHandler
{
    private const string ABI = '[
        {
            "inputs": [
                {
                    "internalType": "address",
                    "name": "player",
                    "type": "address"
                },
                {
                    "internalType": "uint256",
                    "name": "scoreAmount",
                    "type": "uint256"
                },
                {
                    "internalType": "uint256",
                    "name": "transactionAmount",
                    "type": "uint256"
                }
            ],
            "name": "updatePlayerData",
            "outputs": [],
            "stateMutability": "nonpayable",
            "type": "function"
        }
    ]';

    private readonly Web3 $web3;
    private readonly Contract $contract;
    private readonly ?string $serverAddress;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
        string $rpcUrl,
        string $contractAddress,
        private readonly string $privateKey,
        private readonly int $chainId = 10143, // Monad testnet chain ID
    ) {
        $this->web3 = new Web3(new HttpProvider(new HttpRequestManager($rpcUrl)));
        $this->contract = new Contract($this->web3->provider, self::ABI);
        $this->contract->at($contractAddress);
        
        $this->serverAddress = $this->getAddressFromPrivateKey();
    }

    #[Handler]
    public function __invoke(GameEnded $event): void
    {
        $this->logger->info('Game ended, processing scores for blockchain submission', [
            'gameId' => $event->gameId->toString(),
            'winnerId' => $event->winnerId?->toString(),
            'scores' => $event->scores,
        ]);

        // Skip if no private key is configured
        if (empty($this->privateKey)) {
            $this->logger->warning('Skipping blockchain score submission - no private key configured');
            return;
        }

        // Get all players with wallet addresses
        $playerRepository = $this->entityManager->getRepository(Player::class);
        $players = $playerRepository->findBy(['gameId' => $event->gameId]);

        foreach ($players as $player) {
            $walletAddress = $player->getWalletAddress();
            
            if (!$walletAddress) {
                $this->logger->debug('Skipping player without wallet address', [
                    'playerId' => $player->getPlayerId()->toString(),
                    'username' => $player->getUsername(),
                ]);
                continue;
            }

            // Determine score: 1 for winner, 0 for others
            $playerId = $player->getPlayerId();
            $isWinner = $event->winnerId && $event->winnerId->equals($playerId);
            $score = $isWinner ? 1 : 0;

            try {
                $txHash = $this->submitScoreToBlockchain($walletAddress, $score);
                
                $this->logger->info('Successfully submitted score to blockchain', [
                    'gameId' => $event->gameId->toString(),
                    'playerId' => $playerId->toString(),
                    'walletAddress' => $walletAddress,
                    'score' => $score,
                    'isWinner' => $isWinner,
                    'txHash' => $txHash,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to submit score to blockchain', [
                    'gameId' => $event->gameId->toString(),
                    'playerId' => $playerId->toString(),
                    'walletAddress' => $walletAddress,
                    'score' => $score,
                    'isWinner' => $isWinner,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    private function submitScoreToBlockchain(string $playerAddress, int $scoreAmount): string
    {
        if (!$this->serverAddress) {
            throw new \RuntimeException('Server address not available - check private key configuration');
        }

        // Transaction amount is 0 for each game completed
        $transactionAmount = 0;

        // Encode function call
        $functionName = 'updatePlayerData';
        $functionCall = $this->contract->getData($functionName, $playerAddress, $scoreAmount, $transactionAmount);
        
        // The getData method returns hex without 0x prefix
        if (!str_starts_with($functionCall, '0x')) {
            // getData returned raw hex, we'll add 0x later for the transaction
        } else {
            // Remove the 0x prefix if present
            $functionCall = substr($functionCall, 2);
        }

        // Get nonce
        $nonce = null;
        $this->web3->eth->getTransactionCount($this->serverAddress, 'pending', function ($err, $result) use (&$nonce) {
            if ($err !== null) {
                throw new \RuntimeException('Failed to get nonce: ' . $err->getMessage());
            }
            $nonce = $result;
        });

        // Get gas price
        $gasPrice = null;
        $this->web3->eth->gasPrice(function ($err, $result) use (&$gasPrice) {
            if ($err !== null) {
                throw new \RuntimeException('Failed to get gas price: ' . $err->getMessage());
            }
            $gasPrice = $result;
        });

        // Use fixed gas limit (150k should be enough for simple function call)
        $estimatedGas = Utils::toHex(150000, true);

        // Build transaction (convert BigInteger objects to hex strings)
        $nonceHex = is_object($nonce) && method_exists($nonce, 'toHex')
            ? '0x' . $nonce->toHex()
            : (is_object($nonce) && method_exists($nonce, 'toString')
                ? Utils::toHex($nonce->toString(), true)
                : $nonce);
        
        $gasPriceHex = is_object($gasPrice) && method_exists($gasPrice, 'toHex')
            ? '0x' . $gasPrice->toHex()
            : (is_object($gasPrice) && method_exists($gasPrice, 'toString')
                ? Utils::toHex($gasPrice->toString(), true)
                : $gasPrice);
        
        $transaction = [
            'nonce' => $nonceHex,
            'from' => $this->serverAddress,
            'to' => $this->contract->getToAddress(),
            'gas' => $estimatedGas,
            'gasPrice' => $gasPriceHex,
            'value' => '0x0',
            'data' => '0x' . $functionCall,  // Transaction class needs it WITH 0x prefix
            'chainId' => $this->chainId,
        ];

        $this->logger->debug('Preparing transaction', [
            'transaction' => $transaction,
            'playerAddress' => $playerAddress,
            'scoreAmount' => $scoreAmount,
        ]);

        // Sign transaction
        $signedTransaction = $this->signTransaction($transaction);

        // Send transaction
        $txHash = null;
        $this->web3->eth->sendRawTransaction('0x' . $signedTransaction, function ($err, $result) use (&$txHash) {
            if ($err !== null) {
                throw new \RuntimeException('Failed to send transaction: ' . $err->getMessage());
            }
            $txHash = $result;
        });

        return $txHash;
    }

    private function getAddressFromPrivateKey(): ?string
    {
        if (empty($this->privateKey)) {
            return null;
        }

        try {
            $privateKeyHex = str_starts_with($this->privateKey, '0x') 
                ? substr($this->privateKey, 2) 
                : $this->privateKey;
                
            $util = new Util();
            $publicKey = $util->privateKeyToPublicKey($privateKeyHex);
            // publicKeyToAddress already returns the address WITH 0x prefix
            $address = $util->publicKeyToAddress($publicKey);
            
            return $address;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to derive address from private key', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function signTransaction(array $transaction): string
    {
        $privateKeyHex = str_starts_with($this->privateKey, '0x') 
            ? substr($this->privateKey, 2) 
            : $this->privateKey;

        // Create transaction object
        $tx = new Transaction($transaction);
        
        // Sign transaction
        $signedTx = $tx->sign($privateKeyHex);
        
        // Return hex-encoded signed transaction (without 0x prefix)
        return $signedTx;
    }
}