<?php

require 'vendor/autoload.php';
require_once 'includes/connection.php';
require_once 'includes/authMiddleware.php';

header('Content-Type: application/json');

/**
 * GET /invoice/kpi-stats
 *
 * Returns aggregated KPI stats for the Invoice Overview KPI cards.
 *
 * Schema facts (invoice_table):
 *   invoice_amount  VARCHAR(255)  — CAST to DECIMAL(20,2) before arithmetic
 *   invoice_date    VARCHAR(255)  — MariaDB implicitly casts for MONTH()/YEAR()
 *   status          VARCHAR(255)  — Paid | Pending | Overdue | Cancelled | Partially Paid
 *   currency        VARCHAR(255)  — NGN | USD | EUR | GBP
 *
 * WHY $conn->query() and NOT prepare():
 *   No user input reaches this query — all values are server-side rate floats
 *   interpolated by PHP before the string is sent to MariaDB. prepare() on a
 *   query with embedded float literals and no placeholders is unnecessary and
 *   caused the original 500 because the $ngnExpr helper variable contained a
 *   SUM() block that was then nested inside another SUM(CASE ... THEN $ngnExpr),
 *   producing SUM(SUM()) which MariaDB rejects as invalid SQL.
 *
 *   The fix: use $conn->query() directly and write each metric as a single
 *   flat SUM(CASE WHEN <status> THEN CASE <currency> WHEN ... END ELSE 0 END).
 *   No nested aggregates, no helper variables embedded inside aggregates.
 */

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception("Route not found", 400);
    }

    $userData              = authenticateUser();
    $loggedInUserIntegrity = $userData['integrity'];

    if (!in_array($loggedInUserIntegrity, ['Admin', 'Controller'])) {
        throw new Exception("Unauthorized", 401);
    }

    // ════════════════════════════════════════════════════════════════════════
    // STEP 1 — Latest exchange rates
    // ════════════════════════════════════════════════════════════════════════

    $rateStmt = $conn->prepare("
        SELECT usd_rate, eur_rate, gbp_rate
        FROM currency_table
        ORDER BY created_at DESC
        LIMIT 1
    ");
    if (!$rateStmt) throw new Exception("DB Error (rates prepare): " . $conn->error, 500);
    $rateStmt->execute();
    $rates = $rateStmt->get_result()->fetch_assoc();
    $rateStmt->close();

    // Safe float fallback if currency_table is empty
    $usdRate = $rates ? (float) $rates['usd_rate'] : 1.0;
    $eurRate = $rates ? (float) $rates['eur_rate'] : 1.0;
    $gbpRate = $rates ? (float) $rates['gbp_rate'] : 1.0;

    // ════════════════════════════════════════════════════════════════════════
    // STEP 2 — KPI aggregation
    //
    // Each monetary metric is written as a self-contained
    //   SUM( CASE WHEN status = 'X' THEN
    //            CASE currency WHEN 'NGN' THEN amount
    //                          WHEN 'USD' THEN amount * rate
    //                          ...
    //            END
    //        ELSE 0 END )
    //
    // This is a single-level aggregate — no SUM inside SUM.
    // $conn->query() is used because there are no user-supplied parameters.
    // ════════════════════════════════════════════════════════════════════════

    $sql = "
        SELECT
            COUNT(*) AS total_count,

            SUM(status = 'Paid')          AS paid_count,
            SUM(status = 'Pending')       AS pending_count,
            SUM(status = 'Overdue')       AS overdue_count,
            SUM(status = 'Cancelled')     AS cancelled_count,
            SUM(status = 'Partially Paid') AS partial_count,

            /* ── Paid: NGN equivalent ── */
            SUM(
                CASE WHEN status = 'Paid' THEN
                    CASE currency
                        WHEN 'NGN' THEN CAST(invoice_amount AS DECIMAL(20,2))
                        WHEN 'USD' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $usdRate
                        WHEN 'EUR' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $eurRate
                        WHEN 'GBP' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $gbpRate
                        ELSE            CAST(invoice_amount AS DECIMAL(20,2))
                    END
                ELSE 0 END
            ) AS paid_amount_ngn,

            /* ── Pending + Partially Paid: NGN equivalent ── */
            SUM(
                CASE WHEN status IN ('Pending', 'Partially Paid') THEN
                    CASE currency
                        WHEN 'NGN' THEN CAST(invoice_amount AS DECIMAL(20,2))
                        WHEN 'USD' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $usdRate
                        WHEN 'EUR' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $eurRate
                        WHEN 'GBP' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $gbpRate
                        ELSE            CAST(invoice_amount AS DECIMAL(20,2))
                    END
                ELSE 0 END
            ) AS pending_amount_ngn,

            /* ── Overdue: NGN equivalent ── */
            SUM(
                CASE WHEN status = 'Overdue' THEN
                    CASE currency
                        WHEN 'NGN' THEN CAST(invoice_amount AS DECIMAL(20,2))
                        WHEN 'USD' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $usdRate
                        WHEN 'EUR' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $eurRate
                        WHEN 'GBP' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $gbpRate
                        ELSE            CAST(invoice_amount AS DECIMAL(20,2))
                    END
                ELSE 0 END
            ) AS overdue_amount_ngn,

            /* ── This calendar month: count ── */
            SUM(
                CASE
                    WHEN MONTH(invoice_date) = MONTH(CURDATE())
                     AND YEAR(invoice_date)  = YEAR(CURDATE())
                    THEN 1 ELSE 0
                END
            ) AS this_month_count,

            /* ── This calendar month: NGN equivalent ── */
            SUM(
                CASE
                    WHEN MONTH(invoice_date) = MONTH(CURDATE())
                     AND YEAR(invoice_date)  = YEAR(CURDATE())
                    THEN
                        CASE currency
                            WHEN 'NGN' THEN CAST(invoice_amount AS DECIMAL(20,2))
                            WHEN 'USD' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $usdRate
                            WHEN 'EUR' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $eurRate
                            WHEN 'GBP' THEN CAST(invoice_amount AS DECIMAL(20,2)) * $gbpRate
                            ELSE            CAST(invoice_amount AS DECIMAL(20,2))
                        END
                    ELSE 0
                END
            ) AS this_month_amount_ngn

        FROM invoice_table
    ";

    $result = $conn->query($sql);
    if (!$result) throw new Exception("DB Error (kpi query): " . $conn->error, 500);

    $row = $result->fetch_assoc();

    if (!$row) {
        throw new Exception("No data returned from invoice_table.", 500);
    }

    http_response_code(200);
    echo json_encode([
        "status"  => "Success",
        "message" => "Invoice KPIs fetched successfully",
        "data"    => [
            "total" => [
                "count" => (int) $row['total_count'],
            ],
            "paid" => [
                "count"      => (int) $row['paid_count'],
                "amount_ngn" => round((float) ($row['paid_amount_ngn'] ?? 0), 2),
            ],
            "pending" => [
                // Partially Paid folded into Pending for display
                "count"      => (int) $row['pending_count'] + (int) $row['partial_count'],
                "amount_ngn" => round((float) ($row['pending_amount_ngn'] ?? 0), 2),
            ],
            "overdue" => [
                "count"      => (int) $row['overdue_count'],
                "amount_ngn" => round((float) ($row['overdue_amount_ngn'] ?? 0), 2),
            ],
            "cancelled" => [
                "count" => (int) $row['cancelled_count'],
            ],
            "this_month" => [
                "count"      => (int) $row['this_month_count'],
                "amount_ngn" => round((float) ($row['this_month_amount_ngn'] ?? 0), 2),
            ],
        ],
        "rates_used" => [
            "usd" => $usdRate,
            "eur" => $eurRate,
            "gbp" => $gbpRate,
        ],
    ]);

} catch (Exception $e) {
    error_log("Invoice KPI Error: " . $e->getMessage());
    http_response_code($e->getCode() ?: 500);
    echo json_encode(["status" => "Failed", "message" => $e->getMessage()]);
}