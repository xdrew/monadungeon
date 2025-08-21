<?php

declare(strict_types=1);

namespace App\Api\Auth\MonadLogin;

use App\Api\Error;
use App\Infrastructure\Uuid\Uuid;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

final readonly class Action
{
    #[Route('/api/auth/monad-login', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] Request $request): Response|Error
    {
        try {
            // TODO: Validate signature with wallet address
            // For now, we'll accept any valid signature
            
            // Generate or retrieve player ID based on wallet address
            // In a real implementation, you'd check if this wallet already has a player ID
            // and retrieve it from the database, or create a new one
            $playerId = $this->generatePlayerIdFromWallet($request->walletAddress);
            
            return new Response(
                playerId: $playerId,
                walletAddress: $request->walletAddress,
                username: $request->username,
            );
        } catch (\Throwable $e) {
            return new Error(Uuid::v7(), 'Authentication failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Generate a deterministic player ID from wallet address
     * In production, this should check the database for existing players
     */
    private function generatePlayerIdFromWallet(string $walletAddress): Uuid
    {
        // Create a deterministic UUID from the wallet address
        // This ensures the same wallet always gets the same player ID
        $hash = hash('sha256', 'monad-player-' . strtolower($walletAddress));
        $uuidString = substr($hash, 0, 8) . '-' .
                     substr($hash, 8, 4) . '-' .
                     '4' . substr($hash, 13, 3) . '-' .
                     '8' . substr($hash, 17, 3) . '-' .
                     substr($hash, 20, 12);
        
        return Uuid::fromString($uuidString);
    }
}