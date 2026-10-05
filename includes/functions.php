<?php
/**
 * Global Utility Functions
 */

/**
 * JSON response helper
 */
function jsonResponse($success, $data = null, $message = '', $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    
    $response = ['success' => $success];
    
    if ($success) {
        if ($data !== null) {
            $response['data'] = $data;
        }
        if ($message) {
            $response['message'] = $message;
        }
    } else {
        $response['error'] = [
            'message' => $message ?: 'An error occurred',
            'code' => $data['code'] ?? 'ERROR'
        ];
        if (isset($data['details'])) {
            $response['error']['details'] = $data['details'];
        }
    }
    
    echo json_encode($response);
    exit;
}

/**
 * Format bytes to human readable
 */
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Format duration to HH:MM:SS
 */
function formatDuration($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    } else {
        return sprintf('%02d:%02d', $minutes, $secs);
    }
}

/**
 * Time ago format
 */
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 31536000) {
        $months = floor($diff / 2592000);
        return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
    } else {
        $years = floor($diff / 31536000);
        return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
    }
}

/**
 * Get setting value
 */
function getSetting($category, $key, $default = null) {
    static $cache = [];
    $cacheKey = $category . '.' . $key;
    
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    $db = db();
    $setting = $db->fetchOne(
        "SELECT value, type FROM settings WHERE category = ? AND `key` = ?",
        [$category, $key]
    );
    
    if (!$setting) {
        return $default;
    }
    
    $value = $setting['value'];
    
    // Cast to appropriate type
    switch ($setting['type']) {
        case 'INTEGER':
            $value = (int)$value;
            break;
        case 'BOOLEAN':
            $value = (bool)$value;
            break;
        case 'JSON':
            $value = json_decode($value, true);
            break;
    }
    
    $cache[$cacheKey] = $value;
    return $value;
}

/**
 * Update setting value
 */
function updateSetting($category, $key, $value) {
    $db = db();
    
    // Get setting type
    $setting = $db->fetchOne(
        "SELECT type FROM settings WHERE category = ? AND `key` = ?",
        [$category, $key]
    );
    
    if (!$setting) {
        return false;
    }
    
    // Convert value based on type
    if ($setting['type'] === 'JSON') {
        $value = json_encode($value);
    } elseif ($setting['type'] === 'BOOLEAN') {
        $value = $value ? '1' : '0';
    }
    
    return $db->execute(
        "UPDATE settings SET value = ?, updated_at = NOW() WHERE category = ? AND `key` = ?",
        [$value, $category, $key]
    ) > 0;
}

/**
 * Log audit action
 */
function logAudit($action, $description, $entityType = null, $entityId = null, $oldValues = null, $newValues = null) {
    $db = db();
    
    $adminId = $_SESSION['admin_id'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;
    
    $db->execute(
        "INSERT INTO audit_logs (admin_id, user_id, action, entity_type, entity_id, description, old_values, new_values, ip_address, user_agent) 
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $adminId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $description,
            $oldValues ? json_encode($oldValues) : null,
            $newValues ? json_encode($newValues) : null,
            Security::getClientIP(),
            Security::getUserAgent()
        ]
    );
}

/**
 * Check if user has active subscription
 */
function hasActiveSubscription($userId) {
    $db = db();
    
    $subscription = $db->fetchOne(
        "SELECT * FROM subscriptions 
         WHERE user_id = ? AND status = 'ACTIVE' AND end_date > NOW() 
         ORDER BY end_date DESC LIMIT 1",
        [$userId]
    );
    
    return $subscription !== false;
}

/**
 * Get user active subscription
 */
function getActiveSubscription($userId) {
    $db = db();
    
    return $db->fetchOne(
        "SELECT s.*, sp.name as plan_name, sp.duration_days 
         FROM subscriptions s 
         JOIN subscription_plans sp ON s.plan_id = sp.id 
         WHERE s.user_id = ? AND s.status = 'ACTIVE' AND s.end_date > NOW() 
         ORDER BY s.end_date DESC LIMIT 1",
        [$userId]
    );
}

/**
 * Check if user can access video
 */
function canAccessVideo($userId, $video) {
    // Free videos are accessible to everyone
    if ($video['access_type'] === 'FREE') {
        return true;
    }
    
    // Premium videos require active subscription
    return hasActiveSubscription($userId);
}

/**
 * Get user wallet balance
 */
function getWalletBalance($userId) {
    $db = db();
    
    $wallet = $db->fetchOne("SELECT balance FROM wallets WHERE user_id = ?", [$userId]);
    // Returned as a DECIMAL string (e.g. "12.50") – never cast money to float.
    return $wallet ? $wallet['balance'] : '0.00';
}

/**
 * Lock (and lazily create) a user's wallet row. Must be called inside a transaction.
 * Locking the wallet row first also serialises all money operations per user,
 * which is what makes the daily-limit / cooldown checks race-free.
 */
function lockWallet($userId) {
    $db = db();
    if (!$db->getConnection()->inTransaction()) {
        throw new LogicException('lockWallet() requires an open transaction');
    }
    $db->execute("INSERT IGNORE INTO wallets (user_id) VALUES (?)", [$userId]);
    return $db->fetchOne(
        "SELECT balance, lifetime_earned, lifetime_withdrawn FROM wallets WHERE user_id = ? FOR UPDATE",
        [$userId]
    );
}

/**
 * Append a ledger entry and update the wallet balance atomically.
 *
 * - Works standalone (opens its own transaction) OR inside a caller's transaction
 *   (then it neither commits nor rolls back; it throws and the caller rolls back).
 * - $amount is a decimal string/number; positive = credit, negative = debit.
 * - reference_id is UNIQUE in wallet_transactions, so replaying the same
 *   reference can never credit twice (idempotency at the ledger level).
 */
function addWalletTransaction($userId, $type, $amount, $description, $referenceId = null, $referenceType = null, $metadata = null) {
    $db = db();
    $pdo = $db->getConnection();
    $ownsTransaction = !$pdo->inTransaction();
    
    try {
        if ($ownsTransaction) {
            $db->beginTransaction();
        }
        
        $amountPaise = Security::toPaise($amount);
        if ($amountPaise === 0) {
            throw new InvalidArgumentException('Zero amount');
        }
        
        $wallet = lockWallet($userId);
        $beforePaise = Security::toPaise($wallet['balance']);
        $afterPaise = $beforePaise + $amountPaise;
        
        if ($afterPaise < 0) {
            if ($ownsTransaction) {
                $db->rollBack();
            }
            return ['success' => false, 'error' => 'Insufficient balance'];
        }
        
        // Lifetime counters: only genuine earnings count as "earned"; a refund of a
        // rejected withdrawal reverses lifetime_withdrawn instead of inflating earnings.
        $earnedDelta = 0;
        $withdrawnDelta = 0;
        if ($amountPaise > 0 && in_array($type, ['REWARD', 'REFERRAL', 'ADJUSTMENT'], true)) {
            $earnedDelta = $amountPaise;
        } elseif ($type === 'WITHDRAWAL' && $amountPaise < 0) {
            $withdrawnDelta = -$amountPaise;
        } elseif ($type === 'REFUND' && $referenceType === 'withdrawal_refund') {
            $withdrawnDelta = -$amountPaise;
        }
        
        $db->execute(
            "UPDATE wallets SET balance = ?, lifetime_earned = lifetime_earned + ?,
                    lifetime_withdrawn = lifetime_withdrawn + ?, updated_at = NOW()
             WHERE user_id = ?",
            [
                Security::fromPaise($afterPaise),
                Security::fromPaise($earnedDelta),
                Security::fromPaise($withdrawnDelta),
                $userId
            ]
        );
        
        $db->execute(
            "INSERT INTO wallet_transactions (user_id, type, amount, balance_before, balance_after, reference_id, reference_type, description, metadata, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'COMPLETED')",
            [
                $userId,
                $type,
                Security::fromPaise($amountPaise),
                Security::fromPaise($beforePaise),
                Security::fromPaise($afterPaise),
                $referenceId,
                $referenceType,
                $description,
                $metadata ? json_encode($metadata) : null
            ]
        );
        
        $transactionId = $db->lastInsertId();
        
        if ($ownsTransaction) {
            $db->commit();
        }
        
        return [
            'success' => true,
            'transaction_id' => $transactionId,
            'balance_before' => Security::fromPaise($beforePaise),
            'balance_after' => Security::fromPaise($afterPaise)
        ];
        
    } catch (Exception $e) {
        if (!$ownsTransaction) {
            throw $e; // let the caller roll back its whole unit of work
        }
        if ($pdo->inTransaction()) {
            $db->rollBack();
        }
        error_log("Wallet Transaction Error: " . $e->getMessage());
        return ['success' => false, 'error' => 'Transaction failed'];
    }
}

/**
 * Create notification
 */
function createNotification($userId, $type, $title, $message, $data = null) {
    $db = db();
    
    return $db->execute(
        "INSERT INTO notifications (user_id, type, title, message, data) VALUES (?, ?, ?, ?, ?)",
        [$userId, $type, $title, $message, $data ? json_encode($data) : null]
    );
}

/**
 * Paginate results
 */
function paginate($sql, $params, $page = 1, $perPage = 20) {
    $db = db();
    
    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM (" . $sql . ") as count_table";
    $total = $db->fetchOne($countSql, $params)['total'];
    
    // Calculate pagination
    $totalPages = ceil($total / $perPage);
    $offset = ($page - 1) * $perPage;
    
    // Get paginated results
    $paginatedSql = $sql . " LIMIT ? OFFSET ?";
    $paginatedParams = array_merge($params, [$perPage, $offset]);
    $results = $db->fetchAll($paginatedSql, $paginatedParams);
    
    return [
        'data' => $results,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ]
    ];
}

/**
 * Attach a referrer to a user. Only call with a referral code that came from a
 * TRUSTED source (signed initData start_param, or a verified bot webhook update).
 *
 * Anti-abuse rules:
 *  - only brand-new users can be referred (no retroactive referral of old accounts)
 *  - no self-referral, only once per user (referrals.referred_id is UNIQUE)
 *  - the bonus is NOT paid here; cron/referral-rewards.php pays it once the referred
 *    user meets the configured eligibility (minimum real watch time).
 */
function applyReferral($userId, $referralCode, $isNewUser) {
    if (!$isNewUser || !is_string($referralCode) || !preg_match('/^R[0-9A-F]{10}$/', $referralCode)) {
        return false;
    }
    $db = db();
    $referrer = $db->fetchOne(
        "SELECT id FROM users WHERE referral_code = ? AND status = 'ACTIVE'",
        [$referralCode]
    );
    if (!$referrer || (int)$referrer['id'] === (int)$userId) {
        return false;
    }
    $updated = $db->execute(
        "UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL",
        [$referrer['id'], $userId]
    );
    if ($updated) {
        $db->execute(
            "INSERT IGNORE INTO referrals (referrer_id, referred_id) VALUES (?, ?)",
            [$referrer['id'], $userId]
        );
    }
    return (bool)$updated;
}
