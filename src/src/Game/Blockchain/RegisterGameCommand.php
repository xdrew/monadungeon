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
    name: 'game:blockchain:register',
    description: 'Register a game on the Monad blockchain',
)]
final class RegisterGameCommand extends Command
{
    private const string ABI = '[
        {
            "inputs": [
                {
                    "internalType": "address",
                    "name": "_game",
                    "type": "address"
                },
                {
                    "internalType": "string",
                    "name": "_name",
                    "type": "string"
                },
                {
                    "internalType": "string",
                    "name": "_image",
                    "type": "string"
                },
                {
                    "internalType": "string",
                    "name": "_url",
                    "type": "string"
                }
            ],
            "name": "registerGame",
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
                'game-address',
                InputArgument::REQUIRED,
                'The game contract address to register'
            )
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'The name of the game'
            )
            ->addArgument(
                'image',
                InputArgument::REQUIRED,
                'The image URL for the game'
            )
            ->addArgument(
                'url',
                InputArgument::REQUIRED,
                'The game URL'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Simulate the transaction without sending it'
            )
            ->addOption(
                'gas-limit',
                'g',
                InputOption::VALUE_REQUIRED,
                'Custom gas limit for the transaction',
                '300000'
            )
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command registers a game on the blockchain:

  <info>php %command.full_name% 0x1234...abcd "Monadungeon Game" "https://example.com/image.png" "https://example.com/game"</info>

Dry run without sending transaction:
  <info>php %command.full_name% 0x1234...abcd "Monadungeon Game" "https://example.com/image.png" "https://example.com/game" --dry-run</info>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $gameAddress = $input->getArgument('game-address');
        $name = $input->getArgument('name');
        $image = $input->getArgument('image');
        $url = $input->getArgument('url');
        $isDryRun = $input->getOption('dry-run');
        $customGasLimit = (int) $input->getOption('gas-limit');

        // Validate game address
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $gameAddress)) {
            $io->error("Invalid game address format: $gameAddress. Must be 0x followed by 40 hex characters.");
            return Command::FAILURE;
        }

        // Check if private key is configured
        if (empty($this->privateKey)) {
            $io->error('Private key not configured. Please set MONAD_PRIVATE_KEY in .env.local');
            return Command::FAILURE;
        }

        $io->title('Register Game on Blockchain');
        $io->table(
            ['Parameter', 'Value'],
            [
                ['RPC URL', $this->rpcUrl],
                ['Contract', $this->contractAddress],
                ['Chain ID', $this->chainId],
                ['Game Address', $gameAddress],
                ['Name', $name],
                ['Image', $image],
                ['URL', $url],
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

            $functionCall = $contract->getData('registerGame', $gameAddress, $name, $image, $url);
            
            // Debug: Show exactly what getData returns
            $io->info('Raw function call from getData: ' . substr($functionCall, 0, 20) . '...');
            
            // The getData method returns hex without 0x prefix
            // We need to add it for display but the Transaction class expects it without
            if (!str_starts_with($functionCall, '0x')) {
                $functionCallWithPrefix = '0x' . $functionCall;
            } else {
                $functionCallWithPrefix = $functionCall;
                // Remove the 0x for the actual transaction data
                $functionCall = substr($functionCall, 2);
            }
            
            $io->info('Encoded function call: ' . $functionCallWithPrefix);
            
            // Verify the function selector is correct (should be cbaa8d43)
            $selector = substr($functionCall, 0, 8);
            if ($selector !== 'cbaa8d43') {
                $io->warning("Function selector mismatch! Expected cbaa8d43, got: $selector");
            }

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

            // Use custom gas limit or default
            $gasLimit = $customGasLimit;
            $estimatedGas = Utils::toHex($gasLimit, true);
            $io->info("Gas limit: " . $gasLimit . ($customGasLimit !== 300000 ? " (custom)" : " (default)"));

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
            
            $io->info('Transaction data field: ' . substr($transaction['data'], 0, 100) . '...');

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
                'Game registered successfully!',
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
                            '',
                            "Game '$name' registered at address: $gameAddress"
                        ]);
                    } else {
                        $io->error([
                            'Transaction failed on chain!',
                            'Gas used: ' . hexdec($receipt->gasUsed) . ' / ' . $gasLimit,
                            '',
                            'Possible reasons:',
                            '- Insufficient gas limit (try --gas-limit=500000)',
                            '- Contract permissions (only certain addresses can register)',
                            '- Game already registered',
                            '- Invalid parameters',
                            '',
                            'Check transaction on explorer for details:',
                            "https://testnet.monadexplorer.com/tx/$txHash"
                        ]);
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
                'Failed to register game on blockchain',
                $e->getMessage(),
            ]);
            
            $this->logger->error('Blockchain game registration failed', [
                'gameAddress' => $gameAddress,
                'name' => $name,
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
        return $util->publicKeyToAddress($publicKey);
    }

    private function signTransaction(array $transaction): string
    {
        $privateKeyHex = str_starts_with($this->privateKey, '0x') 
            ? substr($this->privateKey, 2) 
            : $this->privateKey;

        $tx = new Transaction($transaction);
        $signedTx = $tx->sign($privateKeyHex);
        
        return $signedTx;
    }
}