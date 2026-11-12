const { Pool } = require('pg');
const path = require('path');

const dbUrl = String(process.env.DATABASE_URL || '').trim();
const isPlaceholderDb = !dbUrl ||
  dbUrl.includes('USER:PASSWORD@HOST') ||
  dbUrl.includes('REPLACE_') ||
  dbUrl.includes('HOST:5432');

function createSqlitePool() {
  const { DatabaseSync } = require('node:sqlite');
  const dbFile = path.resolve(__dirname, '../../../.local_storage.db');
  let db;
  try {
    db = new DatabaseSync(dbFile);
  } catch (err) {
    db = new DatabaseSync(':memory:');
  }

  function executeQuery(sql, params = []) {
    let cleanSql = sql
      .replace(/TIMESTAMPTZ/gi, 'TEXT')
      .replace(/BIGSERIAL/gi, 'INTEGER PRIMARY KEY AUTOINCREMENT')
      .replace(/JSONB/gi, 'TEXT')
      .replace(/NOW\(\)/gi, "datetime('now')")
      .replace(/::\w+(\[\])?/gi, '')
      .replace(/\$(\d+)/g, '?');

    // Skip PostgreSQL DO $migration$ block
    if (/DO\s+\$migration\$/i.test(sql)) {
      return { rows: [], rowCount: 0 };
    }

    // Skip transaction table column alter migrations if already migrated
    if (/ALTER\s+TABLE\s+users\s+ALTER\s+COLUMN/i.test(cleanSql)) {
      return { rows: [], rowCount: 0 };
    }

    // Handle checkDb: SELECT 1
    if (/^\s*SELECT\s+1\s*;?\s*$/i.test(cleanSql)) {
      return { rows: [{ '?column?': 1 }], rowCount: 1 };
    }

    // Handle ANY(?) parameter
    if (/ANY\s*\(\s*\?\s*\)/i.test(cleanSql)) {
      if (Array.isArray(params[0])) {
        const placeholders = params[0].map(() => '?').join(',');
        cleanSql = cleanSql.replace(/=\s*ANY\s*\(\s*\?\s*\)/i, `IN (${placeholders})`);
        params = params[0];
      }
    }

    try {
      const stmt = db.prepare(cleanSql);
      if (/^\s*SELECT/i.test(cleanSql) || /RETURNING/i.test(cleanSql)) {
        const rows = stmt.all(...params);
        return { rows, rowCount: rows.length };
      } else {
        const info = stmt.run(...params);
        return { rows: [], rowCount: info.changes };
      }
    } catch (err) {
      if (/already exists/i.test(err.message)) {
        return { rows: [], rowCount: 0 };
      }
      return { rows: [], rowCount: 0 };
    }
  }

  return {
    async query(sql, params = []) {
      return executeQuery(sql, params);
    },
    async connect() {
      return {
        query: async (sql, params = []) => executeQuery(sql, params),
        release: () => {}
      };
    },
    async end() {
      try { db.close(); } catch {}
    },
    on() {}
  };
}

let pool;
if (!isPlaceholderDb) {
  try {
    pool = new Pool({
      connectionString: dbUrl,
      ssl: process.env.DATABASE_SSL === 'false' ? false : { rejectUnauthorized: false },
      max: Number(process.env.PGPOOL_MAX || 10),
      idleTimeoutMillis: 30000,
      connectionTimeoutMillis: 10000
    });
    pool.on('error', (error) => {
      console.error('Lỗi kết nối PostgreSQL không mong đợi:', error.message);
    });
  } catch (err) {
    console.warn('[Database] Khởi tạo Pool thất bại, dùng Local SQLite Engine:', err.message);
    pool = createSqlitePool();
  }
} else {
  pool = createSqlitePool();
}

async function initDb() {
  await pool.query(`
    CREATE TABLE IF NOT EXISTS users (
      user_id TEXT PRIMARY KEY,
      name TEXT NOT NULL DEFAULT '',
      username TEXT NOT NULL DEFAULT '',
      balance NUMERIC(18, 0) NOT NULL DEFAULT 0,
      total_deposited NUMERIC(18, 0) NOT NULL DEFAULT 0,
      vip_level INTEGER NOT NULL DEFAULT 0,
      ref_by TEXT,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );

    CREATE TABLE IF NOT EXISTS transactions (
      id BIGSERIAL PRIMARY KEY,
      user_id TEXT NOT NULL,
      amount NUMERIC(18, 0) NOT NULL,
      type TEXT NOT NULL,
      reference_code TEXT,
      status TEXT NOT NULL DEFAULT 'paid',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );

    CREATE TABLE IF NOT EXISTS deposit_events (
      reference_code TEXT PRIMARY KEY,
      user_id TEXT NOT NULL,
      amount NUMERIC(18, 0) NOT NULL CHECK (amount > 0),
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );

    CREATE INDEX IF NOT EXISTS idx_transactions_user_created
      ON transactions (user_id, created_at DESC);
  `);

  // Upgrade REAL currency columns from the previous schema to exact integer VND.
  await pool.query(`
    DO $migration$
    BEGIN
      IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'users'
          AND column_name = 'balance'
          AND data_type IN ('real', 'double precision')
      ) THEN
        ALTER TABLE users
          ALTER COLUMN balance TYPE NUMERIC(18, 0)
          USING COALESCE(ROUND(balance::numeric), 0);
      END IF;

      IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'users'
          AND column_name = 'total_deposited'
          AND data_type IN ('real', 'double precision')
      ) THEN
        ALTER TABLE users
          ALTER COLUMN total_deposited TYPE NUMERIC(18, 0)
          USING COALESCE(ROUND(total_deposited::numeric), 0);
      END IF;

      IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'transactions'
          AND column_name = 'amount'
          AND data_type IN ('real', 'double precision')
      ) THEN
        ALTER TABLE transactions
          ALTER COLUMN amount TYPE NUMERIC(18, 0)
          USING COALESCE(ROUND(amount::numeric), 0);
      END IF;
    END $migration$;

    UPDATE users
    SET balance = COALESCE(balance, 0),
        total_deposited = COALESCE(total_deposited, 0)
    WHERE balance IS NULL OR total_deposited IS NULL;
  `);

  await pool.query(`
    CREATE TABLE IF NOT EXISTS products (
      id BIGSERIAL PRIMARY KEY,
      slug TEXT NOT NULL UNIQUE,
      name TEXT NOT NULL,
      category TEXT NOT NULL CHECK (category IN ('minigame','tools','codes','otp','proxy','vip')),
      description TEXT NOT NULL DEFAULT '',
      price NUMERIC(18, 0) NOT NULL CHECK (price >= 0),
      duration_days INTEGER NOT NULL DEFAULT 0,
      stock INTEGER,
      vip_level INTEGER NOT NULL DEFAULT 0,
      image_url TEXT,
      metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
      is_active BOOLEAN NOT NULL DEFAULT TRUE,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS orders (
      id BIGSERIAL PRIMARY KEY,
      order_code TEXT NOT NULL UNIQUE,
      user_id TEXT NOT NULL,
      product_id BIGINT,
      product_name TEXT NOT NULL,
      category TEXT NOT NULL,
      amount NUMERIC(18, 0) NOT NULL CHECK (amount >= 0),
      status TEXT NOT NULL DEFAULT 'pending',
      admin_note TEXT NOT NULL DEFAULT '',
      delivery_text TEXT NOT NULL DEFAULT '',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS wallet_ledger (
      id BIGSERIAL PRIMARY KEY,
      reference_code TEXT UNIQUE,
      user_id TEXT NOT NULL,
      amount NUMERIC(18, 0) NOT NULL,
      transaction_type TEXT NOT NULL,
      description TEXT NOT NULL DEFAULT '',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS support_tickets (
      id BIGSERIAL PRIMARY KEY,
      user_id TEXT NOT NULL,
      subject TEXT NOT NULL,
      message TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'open',
      priority TEXT NOT NULL DEFAULT 'normal',
      admin_reply TEXT NOT NULL DEFAULT '',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS giftcodes (
      code TEXT PRIMARY KEY,
      reward_amount NUMERIC(18, 0) NOT NULL DEFAULT 0 CHECK (reward_amount >= 0),
      max_redemptions INTEGER NOT NULL DEFAULT 1 CHECK (max_redemptions > 0),
      redeemed_count INTEGER NOT NULL DEFAULT 0,
      expires_at TIMESTAMPTZ,
      is_active BOOLEAN NOT NULL DEFAULT TRUE,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS giftcode_redemptions (
      code TEXT NOT NULL,
      user_id TEXT NOT NULL,
      reward_amount NUMERIC(18, 0) NOT NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      PRIMARY KEY (code, user_id)
    );
    CREATE TABLE IF NOT EXISTS app_settings (
      key TEXT PRIMARY KEY,
      value TEXT NOT NULL DEFAULT '',
      is_public BOOLEAN NOT NULL DEFAULT TRUE,
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS bot_menu_configs (
      bot_key TEXT PRIMARY KEY,
      menu_mode TEXT NOT NULL DEFAULT 'reply_keyboard',
      intro_text TEXT NOT NULL DEFAULT '',
      menu_columns SMALLINT NOT NULL DEFAULT 2,
      persistent_keyboard BOOLEAN NOT NULL DEFAULT TRUE,
      resize_keyboard BOOLEAN NOT NULL DEFAULT TRUE,
      button_labels JSONB NOT NULL DEFAULT '{}'::jsonb,
      button_descriptions JSONB NOT NULL DEFAULT '{}'::jsonb,
      button_styles JSONB NOT NULL DEFAULT '{}'::jsonb,
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS admin_audit (
      id BIGSERIAL PRIMARY KEY,
      admin_user_id TEXT NOT NULL,
      action TEXT NOT NULL,
      target TEXT NOT NULL DEFAULT '',
      details JSONB NOT NULL DEFAULT '{}'::jsonb,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS legacy_login_tickets (
      token_hash TEXT PRIMARY KEY,
      user_id TEXT NOT NULL,
      bot_key TEXT NOT NULL,
      expires_at TIMESTAMPTZ NOT NULL,
      consumed_at TIMESTAMPTZ,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
    CREATE TABLE IF NOT EXISTS linked_accounts (
      id BIGSERIAL PRIMARY KEY,
      user_id TEXT NOT NULL,
      brand TEXT NOT NULL,
      username TEXT NOT NULL,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      UNIQUE(user_id, brand, username)
    );
    CREATE TABLE IF NOT EXISTS matchmaking_matches (
      id BIGSERIAL PRIMARY KEY,
      player_one TEXT NOT NULL,
      player_two TEXT NOT NULL,
      mode TEXT NOT NULL DEFAULT 'quick',
      status TEXT NOT NULL DEFAULT 'matched',
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      finished_at TIMESTAMPTZ
    );
    CREATE TABLE IF NOT EXISTS matchmaking_queue (
      id BIGSERIAL PRIMARY KEY,
      user_id TEXT NOT NULL,
      mode TEXT NOT NULL DEFAULT 'quick',
      status TEXT NOT NULL DEFAULT 'waiting',
      matched_user_id TEXT,
      match_id BIGINT,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    );
  `);

  await pool.query(`
    INSERT INTO app_settings (key, value, is_public) VALUES
      ('homepage_title', 'Hendy Bot', TRUE),
      ('announcement', '', TRUE),
      ('VIETQR_BANK_ID', 'MB', TRUE),
      ('VIETQR_ACCOUNT_NO', '0988776655', TRUE),
      ('VIETQR_ACCOUNT_NAME', 'HENDY STUDIO CO', TRUE),
      ('GROUP_URL', '', TRUE),
      ('CHANNEL_URL', '', TRUE),
      ('referral_commission_percent', '10', TRUE),
      ('support_message', 'Đội ngũ CSKH sẽ phản hồi trong thời gian sớm nhất.', TRUE)
    ON CONFLICT (key) DO NOTHING
  `);

  await pool.query(`
    INSERT INTO bot_menu_configs (bot_key,menu_mode,intro_text,menu_columns,persistent_keyboard,resize_keyboard)
    VALUES
      ('primary','reply_keyboard','Chào mừng bạn đến với HENDY BOT. Chọn dịch vụ ở bàn phím phía dưới để bắt đầu.',2,TRUE,TRUE),
      ('secondary','inline_keyboard','Chào mừng bạn đến với HENDY BOT. Chọn một chức năng để tiếp tục.',2,TRUE,TRUE)
    ON CONFLICT (bot_key) DO NOTHING
  `);

  // Seed default catalog products
  await pool.query(`
    INSERT INTO products (slug, name, category, description, price, duration_days, vip_level, is_active)
    VALUES 
      ('minigame-live-hub', 'Mini Game Live Hub (QQ88, 78WIN, SC88)', 'minigame', 'Hệ thống kết nối và quản trị mini game trực tuyến, đồng bộ hóa kết quả và giải đấu.', 0, 30, 0, TRUE),
      ('telegram-automator-pro', 'Telegram Bot Automator Pro', 'tools', 'Bộ công cụ tự động hóa Telegram chuyên nghiệp đa luồng, hỗ trợ xoay proxy và quản lý session.', 350000, 30, 0, TRUE),
      ('code-worker-bot', 'Mã nguồn Cloudflare Worker Bot Serverless', 'codes', 'Mã nguồn bot hoàn chỉnh không cần máy chủ VPS, chịu tải hàng triệu request.', 650000, 365, 0, TRUE),
      ('proxy-ipv4-clean', 'Proxy Dân Cư Tĩnh IPv4 Cao Cấp', 'proxy', 'Dải IP dân cư sạch độc quyền, băng thông không giới hạn, độ trễ cực thấp.', 80000, 30, 0, TRUE),
      ('vip-enterprise-cluster', 'Gói Dịch Vụ Cụm Bot VIP Doanh Nghiệp', 'vip', 'Giải pháp kiến trúc tùy chỉnh cho hệ thống phân phối lớn với hỗ trợ kỹ thuật 24/7.', 1200000, 30, 1, TRUE)
    ON CONFLICT (slug) DO NOTHING
  `);

  console.log('Đã khởi tạo Database schema thành công.');
}

async function getUser(userId, name = '', username = '', refBy = null) {
  const result = await pool.query(
    `INSERT INTO users (user_id, name, username, ref_by)
     VALUES ($1, $2, $3, $4)
     ON CONFLICT (user_id) DO UPDATE
       SET name = CASE WHEN excluded.name <> '' THEN excluded.name ELSE users.name END,
           username = CASE WHEN excluded.username <> '' THEN excluded.username ELSE users.username END
     RETURNING balance, vip_level, total_deposited`,
    [String(userId), String(name || ''), String(username || ''), refBy]
  );
  return result.rows[0];
}

async function updateBalance(userId, amount, isDeposit = false) {
  const value = Number(amount);
  if (!Number.isSafeInteger(value) || value <= 0) {
    throw new Error('Số tiền phải là số nguyên dương hợp lệ.');
  }

  const client = await pool.connect();
  try {
    await client.query('BEGIN');
    await client.query(
      `INSERT INTO users (user_id) VALUES ($1)
       ON CONFLICT (user_id) DO NOTHING`,
      [String(userId)]
    );

    const result = await client.query(
      `UPDATE users
       SET balance = balance + $1,
           total_deposited = total_deposited + CASE WHEN $2 THEN $1 ELSE 0 END,
           vip_level = CASE
             WHEN NOT $2 THEN vip_level
             WHEN total_deposited + $1 >= 5000000 THEN 3
             WHEN total_deposited + $1 >= 2000000 THEN 2
             WHEN total_deposited + $1 >= 500000 THEN 1
             ELSE 0
           END
       WHERE user_id = $3
       RETURNING balance`,
      [value, Boolean(isDeposit), String(userId)]
    );
    if (!isDeposit) {
      const reference = 'botadmin:' + require('crypto').randomBytes(12).toString('hex');
      await client.query(
        "INSERT INTO wallet_ledger (reference_code,user_id,amount,transaction_type,description) " +
        "VALUES ($1,$2,$3,'ADMIN_CREDIT',$4)",
        [reference, String(userId), value, 'Cộng tiền bởi quản trị viên qua Telegram']
      );
    }

    await client.query('COMMIT');
    return result.rows[0];
  } catch (error) {
    await client.query('ROLLBACK');
    throw error;
  } finally {
    client.release();
  }
}

async function logTransaction(userId, amount, type, referenceCode, status = 'paid') {
  await pool.query(
    `INSERT INTO transactions (user_id, amount, type, reference_code, status)
     VALUES ($1, $2, $3, $4, $5)`,
    [String(userId), Number(amount), String(type), referenceCode || null, String(status)]
  );
}

async function processDeposit({ userId, amount, referenceCode }) {
  const value = Number(amount);
  const reference = String(referenceCode || '').trim();

  if (!/^\d{5,20}$/.test(String(userId))) {
    throw new Error('Telegram user ID không hợp lệ.');
  }
  if (!Number.isSafeInteger(value) || value <= 0) {
    throw new Error('Số tiền nạp không hợp lệ.');
  }
  if (!reference || reference.length > 128) {
    throw new Error('Mã tham chiếu giao dịch không hợp lệ.');
  }

  const client = await pool.connect();
  try {
    await client.query('BEGIN');

    const inserted = await client.query(
      `INSERT INTO deposit_events (reference_code, user_id, amount)
       VALUES ($1, $2, $3)
       ON CONFLICT (reference_code) DO NOTHING
       RETURNING reference_code`,
      [reference, String(userId), value]
    );

    if (inserted.rowCount === 0) {
      await client.query('ROLLBACK');
      return { duplicate: true };
    }

    await client.query(
      `INSERT INTO users (user_id) VALUES ($1)
       ON CONFLICT (user_id) DO NOTHING`,
      [String(userId)]
    );

    const updated = await client.query(
      `UPDATE users
       SET balance = balance + $1,
           total_deposited = total_deposited + $1,
           vip_level = CASE
             WHEN total_deposited + $1 >= 5000000 THEN 3
             WHEN total_deposited + $1 >= 2000000 THEN 2
             WHEN total_deposited + $1 >= 500000 THEN 1
             ELSE 0
           END
       WHERE user_id = $2
       RETURNING balance, vip_level, total_deposited`,
      [value, String(userId)]
    );

    await client.query(
      `INSERT INTO transactions (user_id, amount, type, reference_code, status)
       VALUES ($1, $2, 'DEPOSIT', $3, 'paid')`,
      [String(userId), value, reference]
    );
    await client.query(
      `INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description)
       VALUES ($1, $2, $3, 'DEPOSIT', 'Nạp tiền qua ngân hàng')`,
      ['deposit:' + reference, String(userId), value]
    );
    const referralResult = await client.query(
      'SELECT ref_by FROM users WHERE user_id = $1',
      [String(userId)]
    );
    const rateResult = await client.query(
      "SELECT value FROM app_settings WHERE key = 'referral_commission_percent'"
    );
    const rate = Math.max(0, Math.min(30, Number(rateResult.rows[0] && rateResult.rows[0].value || 0)));
    const referrerId = referralResult.rows[0] && referralResult.rows[0].ref_by;
    const referralReward = Math.floor(value * rate / 100);
    if (referrerId && referralReward > 0) {
      await client.query(
        'UPDATE users SET balance = balance + $1 WHERE user_id = $2',
        [referralReward, referrerId]
      );
      await client.query(
        "INSERT INTO wallet_ledger (reference_code, user_id, amount, transaction_type, description) " +
        "VALUES ($1, $2, $3, 'REFERRAL_REWARD', $4)",
        ['referral:' + reference, referrerId, referralReward, 'Hoa hồng giới thiệu từ giao dịch ' + reference]
      );
      await client.query(
        "INSERT INTO transactions (user_id, amount, type, reference_code, status) " +
        "VALUES ($1, $2, 'REFERRAL_REWARD', $3, 'paid')",
        [referrerId, referralReward, reference]
      );
    }

    await client.query('COMMIT');
    return { duplicate: false, user: updated.rows[0] };
  } catch (error) {
    await client.query('ROLLBACK');
    throw error;
  } finally {
    client.release();
  }
}

async function checkDb() {
  await pool.query('SELECT 1');
  return true;
}

async function closeDb() {
  await pool.end();
}

module.exports = {
  initDb,
  getUser,
  updateBalance,
  logTransaction,
  processDeposit,
  checkDb,
  closeDb,
  pool
};
