# Monad Games ID Integration

This document describes the integration of Monad Games ID authentication into the game.

## Overview

Monad Games ID is a cross-game identity solution that allows players to:
- Use a consistent username across multiple games
- Authenticate using their Privy Global wallet
- Have their scores and achievements tracked on-chain
- Participate in cross-game leaderboards

## Architecture

### Backend Components

1. **MonadAuthService** (`src/Game/Auth/MonadAuthService.php`)
   - Handles authentication with Monad Games ID
   - Verifies wallet signatures
   - Retrieves player information
   - Submits scores and transactions on-chain

2. **API Endpoints**
   - `POST /api/auth/monad-login` - Authenticate with Monad Games ID
   - `POST /api/score/submit` - Submit player scores on-chain

### Frontend Components

1. **MonadAuthService** (`fe/src/services/monadAuth.js`)
   - Integrates with Privy SDK for wallet authentication
   - Manages user sessions
   - Handles score submission

2. **UI Components**
   - Updated HomeView with "Sign in with Monad Games ID" button
   - Monad-branded login button with gradient styling

## Setup Instructions

### Backend Setup

1. The backend services are already implemented and ready to use.

2. When Monad provides the production API endpoints, update:
   - `MonadAuthService::MONAD_API_BASE` constant
   - Implement actual API calls in `verifySignature()` and `getPlayerInfo()`

### Frontend Setup

1. Install dependencies:
   ```bash
   cd fe
   npm install
   ```

2. Configure Privy:
   - Get your Privy App ID from https://dashboard.privy.io
   - Create `.env` file in `fe/` directory:
   ```env
   VITE_PRIVY_APP_ID=your_privy_app_id_here
   ```

3. Start the development server:
   ```bash
   npm run dev
   ```

## Authentication Flow

1. **Player clicks "Sign in with Monad Games ID"**
   - Frontend initializes Privy SDK
   - Privy prompts user to connect their wallet

2. **Wallet Connection**
   - User connects their wallet (MetaMask, WalletConnect, etc.)
   - Privy handles the wallet connection flow

3. **Signature Generation**
   - Frontend requests user to sign a message
   - Signature proves ownership of the wallet address

4. **Backend Verification**
   - Frontend sends wallet address and signature to `/api/auth/monad-login`
   - Backend verifies the signature with Monad Games ID
   - Returns player ID, username, and wallet address

5. **Session Creation**
   - Frontend stores player data in localStorage
   - Player can now create or join games with their Monad identity

## Score Submission

When a game ends or a player achieves a milestone:

1. Frontend calls `monadAuth.submitScore(score, metadata)`
2. Score is sent to `/api/score/submit` endpoint
3. Backend submits the score on-chain via Monad Games ID
4. Transaction hash is returned for verification

## Testing

### Manual Testing

1. Start the full stack:
   ```bash
   task run
   ```

2. Navigate to http://localhost:3000

3. Click "Sign in with Monad Games ID"

4. Complete the wallet connection flow

5. Verify that:
   - User is authenticated
   - Username is displayed
   - Can create/join games
   - Scores are submitted on-chain

### Integration Testing

The current implementation uses mock data for testing. When Monad provides:
- Production API endpoints
- Verification mechanism documentation
- On-chain contract addresses

Update the `MonadAuthService` to use the real endpoints.

## Security Considerations

1. **Signature Verification**
   - All wallet signatures must be verified server-side
   - Never trust client-provided wallet addresses without verification

2. **Session Management**
   - Player sessions are stored in localStorage
   - Consider implementing JWT tokens for production

3. **API Rate Limiting**
   - Implement rate limiting on authentication endpoints
   - Prevent spam score submissions

## Future Enhancements

1. **Cross-Game Leaderboards**
   - Display leaderboards from multiple games
   - Show player rankings across the Monad ecosystem

2. **Achievement System**
   - Track player achievements on-chain
   - Display badges and rewards

3. **NFT Integration**
   - Mint achievement NFTs
   - Allow players to showcase their collections

4. **Social Features**
   - Friend lists using Monad Games ID
   - Cross-game messaging
   - Guild/clan systems

## Support

For issues or questions about the integration:
1. Check the Monad Games ID documentation
2. Contact the Monad Foundation support team
3. Review the implementation in this repository