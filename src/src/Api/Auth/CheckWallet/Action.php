<?php

declare(strict_types=1);

namespace App\Api\Auth\CheckWallet;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class Action
{
    #[Route('/api/check-wallet', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $walletAddress = $request->query->get('walletAddress');
        
        if (!$walletAddress) {
            return new JsonResponse(['error' => 'Wallet address is required'], 400);
        }
        
        // TODO: Check if this wallet has a registered Monad Games ID username
        // For now, we'll return a mock response
        // In production, this would query the Monad Games ID service or database
        
        // Mock some wallets with usernames for testing
        $knownWallets = [
            '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb8' => 'player1',
            '0x5aAeb6053f3E94C9b9A09f33669435E7Ef1BeAed' => 'gamemaster',
            '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359' => 'cryptoknight',
        ];
        
        $walletLower = strtolower($walletAddress);
        foreach ($knownWallets as $known => $username) {
            if (strtolower($known) === $walletLower) {
                return new JsonResponse([
                    'hasUsername' => true,
                    'username' => $username,
                ]);
            }
        }
        
        return new JsonResponse([
            'hasUsername' => false,
            'username' => null,
        ]);
    }
}