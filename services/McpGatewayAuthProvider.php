<?php
/**
 * McpGatewayAuthProvider - Auth provider for the /mcp gateway endpoint
 *
 * Wraps ApiAuthService::authenticate('mcp', 'tools') to bridge
 * the existing auth logic into fastmcphp's AuthProviderInterface.
 *
 * Returns unauthenticated() when no token is present, allowing
 * initialize and ping to proceed without credentials.
 *
 * Stores the authenticated member for tool handlers to access,
 * since Server's Context doesn't pass auth info to Tool::execute().
 */

namespace app\services;

use Fastmcphp\Server\Auth\AuthProviderInterface;
use Fastmcphp\Server\Auth\AuthRequest;
use Fastmcphp\Server\Auth\AuthResult;
use Fastmcphp\Server\Auth\AuthenticatedUser;

class McpGatewayAuthProvider implements AuthProviderInterface
{
    private ?int $memberId = null;
    private ?object $memberBean = null;

    public function authenticate(AuthRequest $request): AuthResult
    {
        // No token = unauthenticated (allows initialize/ping to proceed)
        $token = $request->getToken();
        if (empty($token)) {
            return AuthResult::unauthenticated();
        }

        // Delegate to existing ApiAuthService
        $result = ApiAuthService::authenticate('mcp', 'tools');

        if (!$result['success']) {
            return AuthResult::failed($result['error'] ?? 'Authentication failed');
        }

        $member = $result['member'];
        $this->memberId = (int) $member->id;
        $this->memberBean = $member;

        return AuthResult::success(
            new AuthenticatedUser(
                id: (string) $member->id,
                name: $member->firstname ?? null,
                email: $member->email ?? null,
                level: (int) ($member->level ?? 100),
                scopes: ['tools:*'],
            )
        );
    }

    public function getMemberId(): ?int
    {
        return $this->memberId;
    }

    public function getMemberBean(): ?object
    {
        return $this->memberBean;
    }
}
