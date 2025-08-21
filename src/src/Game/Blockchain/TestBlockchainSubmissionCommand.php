<?php

declare(strict_types=1);

namespace App\Game\Blockchain;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Web3\Contract;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Web3\Utils;
use Web3\Web3;
use Web3p\EthereumTx\Transaction;
use Web3p\EthereumUtil\Util;

#[AsCommand(
    name: 'game:blockchain:test',
    description: 'Test blockchain score submission to Monad smart contract',
)]
final class TestBlockchainSubmissionCommand extends Command
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

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $rpcUrl,
        private readonly string $contractAddress,
        private readonly string $privateKey,
        private readonly int $chainId = 10143, // Monad testnet chain ID
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'player-address',
                InputArgument::REQUIRED,
                'The player wallet address to submit score for'
            )
            ->addOption(
                'score',
                's',
                InputOption::VALUE_REQUIRED,
                'Score amount to submit (0 or 1)',
                '1'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Simulate the transaction without sending it'
            )
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command tests blockchain score submission:

  <info>php %command.full_name% 0x1234...abcd</info>

Submit a winning score (1 point):
  <info>php %command.full_name% 0x1234...abcd --score=1</info>

Submit a losing score (0 points):
  <info>php %command.full_name% 0x1234...abcd --score=0</info>

Dry run without sending transaction:
  <info>php %command.full_name% 0x1234...abcd --dry-run</info>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $playerAddress = $input->getArgument('player-address');
        $scoreAmount = (int) $input->getOption('score');
        $isDryRun = $input->getOption('dry-run');

        // Validate inputs
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $playerAddress)) {
            $io->error('Invalid player address format. Must be 0x followed by 40 hex characters.');
            return Command::FAILURE;
        }

        if ($scoreAmount !== 0 && $scoreAmount !== 1) {
            $io->error('Score must be 0 or 1');
            return Command::FAILURE;
        }

        // Check if private key is configured
        if (empty($this->privateKey)) {
            $io->error('Private key not configured. Please set MONAD_PRIVATE_KEY in .env.local');
            return Command::FAILURE;
        }

        $io->title('Blockchain Score Submission Test');
        $io->table(
            ['Parameter', 'Value'],
            [
                ['RPC URL', $this->rpcUrl],
                ['Contract', $this->contractAddress],
                ['Chain ID', $this->chainId],
                ['Player Address', $playerAddress],
                ['Score Amount', $scoreAmount],
                ['Transaction Amount', '1'],
                ['Mode', $isDryRun ? 'DRY RUN' : 'LIVE'],
            ]
        );

        try {
            // Initialize Web3
            $web3 = new Web3(new HttpProvider(new HttpRequestManager($this->rpcUrl)));
            
            // Get server address from private key
            $serverAddress = $this->getAddressFromPrivateKey();
            $io->info("Server wallet address: $serverAddress");

            // Check server wallet balance
            $balance = null;
            $web3->eth->getBalance($serverAddress, function ($err, $result) use (&$balance) {
                if ($err !== null) {
                    throw new \RuntimeException('Failed to get balance: ' . $err->getMessage());
                }
                $balance = $result;
            });

            $balanceString = $balance->toString();
            $balanceInEther = Utils::fromWei($balanceString, 'ether');
            $io->info("Server wallet balance: " . (is_array($balanceInEther) ? $balanceInEther[0] : $balanceInEther) . " MONAD");

            if ($balance->toString() === '0') {
                $io->warning('Server wallet has no MONAD tokens for gas fees!');
                $io->note("Send MONAD testnet tokens to: $serverAddress");
                
                if (!$io->confirm('Continue anyway?', false)) {
                    return Command::FAILURE;
                }
            }

            // Prepare contract call
            $contract = new Contract($web3->provider, self::ABI);
            $contract->at($this->contractAddress);

            $functionCall = $contract->getData('updatePlayerData', $playerAddress, $scoreAmount, 1);
            
            // The getData method returns hex without 0x prefix
            if (!str_starts_with($functionCall, '0x')) {
                $functionCallWithPrefix = '0x' . $functionCall;
            } else {
                $functionCallWithPrefix = $functionCall;
                // Remove the 0x for later use
                $functionCall = substr($functionCall, 2);
            }
            
            $io->info('Encoded function call: ' . $functionCallWithPrefix);

            if ($isDryRun) {
                $io->success('Dry run completed successfully. Transaction not sent.');
                return Command::SUCCESS;
            }

            // Get nonce
            $io->section('Preparing Transaction');
            $nonce = null;
            $web3->eth->getTransactionCount($serverAddress, 'pending', function ($err, $result) use (&$nonce) {
                if ($err !== null) {
                    throw new \RuntimeException('Failed to get nonce: ' . $err->getMessage());
                }
                $nonce = $result;
            });
            $io->info("Nonce: " . $nonce->toString());

            // Get gas price
            $gasPrice = null;
            $web3->eth->gasPrice(function ($err, $result) use (&$gasPrice) {
                if ($err !== null) {
                    throw new \RuntimeException('Failed to get gas price: ' . $err->getMessage());
                }
                $gasPrice = $result;
            });
            $gasPriceString = is_object($gasPrice) && method_exists($gasPrice, 'toString') 
                ? $gasPrice->toString() 
                : (string) $gasPrice;
            $gasPriceInGwei = Utils::fromWei($gasPriceString, 'gwei');
            $io->info("Gas price: " . (is_array($gasPriceInGwei) ? $gasPriceInGwei[0] : $gasPriceInGwei) . " Gwei");

            // Use fixed gas limit for now (150k should be enough for simple function call)
            $estimatedGas = Utils::toHex(150000, true);
            $gasLimit = 150000;
            $io->info("Gas limit: " . $gasLimit . " (fixed)");

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
                'from' => $serverAddress,
                'to' => $this->contractAddress,
                'gas' => $estimatedGas,
                'gasPrice' => $gasPriceHex,
                'value' => '0x0',
                'data' => '0x' . $functionCall,  // Transaction class needs it WITH 0x prefix
                'chainId' => $this->chainId,
            ];

            // Sign transaction
            $io->section('Signing and Sending Transaction');
            $signedTx = $this->signTransaction($transaction);
            $io->info('Transaction signed successfully');

            // Send transaction
            $txHash = null;
            $web3->eth->sendRawTransaction('0x' . $signedTx, function ($err, $result) use (&$txHash) {
                if ($err !== null) {
                    throw new \RuntimeException('Failed to send transaction: ' . $err->getMessage());
                }
                $txHash = $result;
            });

            $io->success([
                'Transaction sent successfully!',
                "Transaction hash: $txHash",
                '',
                'View on explorer:',
                "https://testnet.monadexplorer.com/tx/$txHash"
            ]);

            // Wait for confirmation
            $io->section('Waiting for Confirmation');
            $io->progressStart(30);
            
            $confirmed = false;
            $attempts = 0;
            while (!$confirmed && $attempts < 30) {
                sleep(2);
                $io->progressAdvance();
                
                $receipt = null;
                $web3->eth->getTransactionReceipt($txHash, function ($err, $result) use (&$receipt) {
                    if ($err === null && $result !== null) {
                        $receipt = $result;
                    }
                });
                
                if ($receipt) {
                    $confirmed = true;
                    $io->progressFinish();
                    
                    if ($receipt->status === '0x1') {
                        $io->success([
                            'Transaction confirmed!',
                            'Block number: ' . hexdec($receipt->blockNumber),
                            'Gas used: ' . hexdec($receipt->gasUsed),
                        ]);
                    } else {
                        $io->error('Transaction failed on chain!');
                        return Command::FAILURE;
                    }
                }
                
                $attempts++;
            }
            
            if (!$confirmed) {
                $io->progressFinish();
                $io->warning('Transaction not confirmed after 60 seconds. Check explorer for status.');
            }

            return Command::SUCCESS;
            
        } catch (\Throwable $e) {
            $io->error([
                'Failed to submit score to blockchain',
                $e->getMessage(),
            ]);
            
            $this->logger->error('Blockchain test submission failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return Command::FAILURE;
        }
    }

    private function getAddressFromPrivateKey(): string
    {
        $privateKeyHex = str_starts_with($this->privateKey, '0x') 
            ? substr($this->privateKey, 2) 
            : $this->privateKey;
            
        $util = new Util();
        $publicKey = $util->privateKeyToPublicKey($privateKeyHex);
        // publicKeyToAddress already returns the address WITH 0x prefix
        return $util->publicKeyToAddress($publicKey);
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