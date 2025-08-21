<?php

declare(strict_types=1);

namespace App\Game\Blockchain;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Web3\Contract;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;
use Web3\Utils;
use Web3\Web3;

#[AsCommand(
    name: 'game:blockchain:check-permissions',
    description: 'Check contract permissions and ownership',
)]
final class CheckContractPermissionsCommand extends Command
{
    // Common ownership/permission function signatures
    private const string ABI = '[
        {
            "inputs": [],
            "name": "owner",
            "outputs": [
                {
                    "internalType": "address",
                    "name": "",
                    "type": "address"
                }
            ],
            "stateMutability": "view",
            "type": "function"
        },
        {
            "inputs": [],
            "name": "admin",
            "outputs": [
                {
                    "internalType": "address",
                    "name": "",
                    "type": "address"
                }
            ],
            "stateMutability": "view",
            "type": "function"
        },
        {
            "inputs": [
                {
                    "internalType": "address",
                    "name": "account",
                    "type": "address"
                }
            ],
            "name": "isAuthorized",
            "outputs": [
                {
                    "internalType": "bool",
                    "name": "",
                    "type": "bool"
                }
            ],
            "stateMutability": "view",
            "type": "function"
        },
        {
            "inputs": [
                {
                    "internalType": "bytes32",
                    "name": "role",
                    "type": "bytes32"
                },
                {
                    "internalType": "address",
                    "name": "account",
                    "type": "address"
                }
            ],
            "name": "hasRole",
            "outputs": [
                {
                    "internalType": "bool",
                    "name": "",
                    "type": "bool"
                }
            ],
            "stateMutability": "view",
            "type": "function"
        },
        {
            "inputs": [],
            "name": "DEFAULT_ADMIN_ROLE",
            "outputs": [
                {
                    "internalType": "bytes32",
                    "name": "",
                    "type": "bytes32"
                }
            ],
            "stateMutability": "view",
            "type": "function"
        },
        {
            "inputs": [],
            "name": "REGISTRAR_ROLE",
            "outputs": [
                {
                    "internalType": "bytes32",
                    "name": "",
                    "type": "bytes32"
                }
            ],
            "stateMutability": "view",
            "type": "function"
        }
    ]';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $rpcUrl,
        private readonly string $contractAddress,
        private readonly string $privateKey,
        private readonly int $chainId = 10143,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'check-address',
                'a',
                InputOption::VALUE_REQUIRED,
                'Check if a specific address has permissions'
            )
            ->setHelp(<<<'EOF'
The <info>%command.name%</info> command checks contract permissions:

  <info>php %command.full_name%</info>

Check specific address permissions:
  <info>php %command.full_name% --check-address=0x1234...abcd</info>
EOF
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $checkAddress = $input->getOption('check-address');

        $io->title('Contract Permissions Check');
        $io->table(
            ['Parameter', 'Value'],
            [
                ['RPC URL', $this->rpcUrl],
                ['Contract', $this->contractAddress],
                ['Chain ID', $this->chainId],
            ]
        );

        try {
            $web3 = new Web3(new HttpProvider(new HttpRequestManager($this->rpcUrl)));
            $contract = new Contract($web3->provider, self::ABI);
            $contract->at($this->contractAddress);

            // Get server address from private key
            $serverAddress = $this->getAddressFromPrivateKey();
            $io->section('Current Server Wallet');
            $io->info("Address: $serverAddress");

            // Check owner
            $io->section('Contract Ownership');
            $owner = null;
            $hasOwner = false;
            
            try {
                $contract->call('owner', function ($err, $result) use (&$owner, &$hasOwner) {
                    if ($err === null && $result) {
                        $owner = $result[0] ?? null;
                        $hasOwner = true;
                    }
                });
                
                if ($hasOwner && $owner) {
                    $io->info("Contract Owner: $owner");
                    if (strtolower($owner) === strtolower($serverAddress)) {
                        $io->success('✓ Server wallet IS the owner');
                    } else {
                        $io->warning('✗ Server wallet is NOT the owner');
                    }
                }
            } catch (\Throwable $e) {
                $io->note('No owner() function found');
            }

            // Check admin
            $admin = null;
            $hasAdmin = false;
            
            try {
                $contract->call('admin', function ($err, $result) use (&$admin, &$hasAdmin) {
                    if ($err === null && $result) {
                        $admin = $result[0] ?? null;
                        $hasAdmin = true;
                    }
                });
                
                if ($hasAdmin && $admin) {
                    $io->info("Contract Admin: $admin");
                    if (strtolower($admin) === strtolower($serverAddress)) {
                        $io->success('✓ Server wallet IS the admin');
                    } else {
                        $io->warning('✗ Server wallet is NOT the admin');
                    }
                }
            } catch (\Throwable $e) {
                $io->note('No admin() function found');
            }

            // Check if server is authorized
            $io->section('Authorization Checks');
            
            try {
                $isAuthorized = null;
                $contract->call('isAuthorized', $serverAddress, function ($err, $result) use (&$isAuthorized) {
                    if ($err === null && $result) {
                        $isAuthorized = $result[0] ?? false;
                    }
                });
                
                if ($isAuthorized !== null) {
                    if ($isAuthorized) {
                        $io->success("✓ Server wallet IS authorized");
                    } else {
                        $io->warning("✗ Server wallet is NOT authorized");
                    }
                }
            } catch (\Throwable $e) {
                $io->note('No isAuthorized() function found');
            }

            // Check for role-based access control
            $io->section('Role-Based Access Control');
            
            try {
                // Check DEFAULT_ADMIN_ROLE
                $adminRole = null;
                $contract->call('DEFAULT_ADMIN_ROLE', function ($err, $result) use (&$adminRole) {
                    if ($err === null && $result) {
                        $adminRole = $result[0] ?? null;
                    }
                });
                
                if ($adminRole) {
                    $hasAdminRole = null;
                    $contract->call('hasRole', $adminRole, $serverAddress, function ($err, $result) use (&$hasAdminRole) {
                        if ($err === null && $result) {
                            $hasAdminRole = $result[0] ?? false;
                        }
                    });
                    
                    if ($hasAdminRole !== null) {
                        if ($hasAdminRole) {
                            $io->success("✓ Server wallet HAS DEFAULT_ADMIN_ROLE");
                        } else {
                            $io->warning("✗ Server wallet does NOT have DEFAULT_ADMIN_ROLE");
                        }
                    }
                }
            } catch (\Throwable $e) {
                $io->note('No role-based access control found');
            }

            // Check REGISTRAR_ROLE
            try {
                $registrarRole = null;
                $contract->call('REGISTRAR_ROLE', function ($err, $result) use (&$registrarRole) {
                    if ($err === null && $result) {
                        $registrarRole = $result[0] ?? null;
                    }
                });
                
                if ($registrarRole) {
                    $hasRegistrarRole = null;
                    $contract->call('hasRole', $registrarRole, $serverAddress, function ($err, $result) use (&$hasRegistrarRole) {
                        if ($err === null && $result) {
                            $hasRegistrarRole = $result[0] ?? false;
                        }
                    });
                    
                    if ($hasRegistrarRole !== null) {
                        if ($hasRegistrarRole) {
                            $io->success("✓ Server wallet HAS REGISTRAR_ROLE");
                        } else {
                            $io->warning("✗ Server wallet does NOT have REGISTRAR_ROLE");
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Silent fail
            }

            // Check specific address if provided
            if ($checkAddress) {
                if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $checkAddress)) {
                    $io->error("Invalid address format: $checkAddress");
                    return Command::FAILURE;
                }

                $io->section("Checking permissions for: $checkAddress");

                // Check if owner
                if ($hasOwner && $owner) {
                    if (strtolower($owner) === strtolower($checkAddress)) {
                        $io->success('✓ This address IS the owner');
                    } else {
                        $io->info('✗ This address is NOT the owner');
                    }
                }

                // Check if admin
                if ($hasAdmin && $admin) {
                    if (strtolower($admin) === strtolower($checkAddress)) {
                        $io->success('✓ This address IS the admin');
                    } else {
                        $io->info('✗ This address is NOT the admin');
                    }
                }

                // Check if authorized
                try {
                    $isAuthorized = null;
                    $contract->call('isAuthorized', $checkAddress, function ($err, $result) use (&$isAuthorized) {
                        if ($err === null && $result) {
                            $isAuthorized = $result[0] ?? false;
                        }
                    });
                    
                    if ($isAuthorized !== null) {
                        if ($isAuthorized) {
                            $io->success("✓ This address IS authorized");
                        } else {
                            $io->info("✗ This address is NOT authorized");
                        }
                    }
                } catch (\Throwable $e) {
                    // Silent fail
                }
            }

            $io->section('Summary');
            $io->warning([
                'If the server wallet lacks necessary permissions,',
                'contact the contract owner to grant access.',
                '',
                'The registerGame function likely requires:',
                '- Owner/admin privileges',
                '- REGISTRAR_ROLE',
                '- Or being on an authorized list'
            ]);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $io->error([
                'Failed to check contract permissions',
                $e->getMessage(),
            ]);
            
            $this->logger->error('Contract permissions check failed', [
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
            
        $util = new \Web3p\EthereumUtil\Util();
        $publicKey = $util->privateKeyToPublicKey($privateKeyHex);
        return $util->publicKeyToAddress($publicKey);
    }
}