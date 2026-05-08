# TalentLink — Security Implementation Guide

## 🔒 Implemented Security Measures

### 1. **Environment Configuration**
- ✅ Secrets moved to `.env` file (never committed)
- ✅ JWT_SECRET minimum 32 characters (enforced)
- ✅ CORS configurable (not open to `*` by default)
- ✅ Database credentials in environment variables
- ✅ Automatic validation on startup

### 2. **Authentication & Password**
- ✅ Bcrypt with cost=12 (stronger hashing)
- ✅ Minimum 8 characters for passwords (enforced)
- ✅ Email validation (filter_var FILTER_VALIDATE_EMAIL)
- ✅ JWT with expiration (8 hours default)
- ✅ Token signature verification (hash_equals)
- ✅ Login attempt logging

### 3. **Authorization & Access Control**
- ✅ `requireAuth()` on all protected endpoints
- ✅ User ownership verification (can only modify own data)
- ✅ Role-based access control (recruteur, candidat, particulier)
- ✅ Authorization checks on conversations and applications
- ✅ Recruiter verification for candidatures

### 4. **Input Validation**
- ✅ Email format validation (FILTER_VALIDATE_EMAIL)
- ✅ Date format validation (YYYY-MM-DD regex)
- ✅ Time format validation (HH:MM:SS regex)
- ✅ String length limits (title: 3-200, message: max 5000)
- ✅ Enum validation (whitelist for statuses, types, roles)
- ✅ Type casting (intval for IDs)
- ✅ Trimming user input

### 5. **SQL Injection Prevention** ⭐
- ✅ Prepared statements (PDO with bound parameters)
- ✅ **Whitelist validation for dynamic table names** (FIXED!)
  ```php
  if (!in_array($type, ['offres', 'missions'])) {
      jsonError('Invalid type', 400);
  }
  $table = $type === 'missions' ? 'missions' : 'offres';
  ```
- ✅ No string interpolation in queries

### 6. **Error Handling**
- ✅ Errors hidden from users (APP_DEBUG mode)
- ✅ Errors logged to `logs/errors_YYYY-MM-DD.log`
- ✅ No stack traces in production
- ✅ Generic error messages for security

### 7. **Security Headers**
- ✅ X-Content-Type-Options: nosniff
- ✅ X-Frame-Options: DENY
- ✅ X-XSS-Protection: 1; mode=block
- ✅ Content-Type: application/json

### 8. **Database Transactions**
- ✅ Atomic operations (begin/commit/rollback)
- ✅ Data consistency on failures
- ✅ Prevents partial updates

### 9. **Logging**
- ✅ Authentication attempts logged
- ✅ Error logging with timestamps
- ✅ Separate log files per day
- ✅ `/logs/` directory in .gitignore

---

## 🚀 Setup Instructions

### Step 1: Create `.env` file
```bash
cp .env.example .env
```

### Step 2: Generate JWT Secret
```bash
openssl rand -base64 32
# Copy the output to JWT_SECRET in .env
```

### Step 3: Configure Database
```env
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASS=your_password
DB_NAME=talentlink_db
JWT_SECRET=your_32_chars_key
CORS_ORIGIN=https://yourdomain.com
APP_ENV=production
APP_DEBUG=false
```

### Step 4: Set Permissions
```bash
chmod 600 .env         # Only owner can read
chmod 755 logs/        # Logs directory
chown www-data:www-data logs/  # For Apache/Nginx
```

---

## 📋 Production Checklist

- [ ] `.env.local` created and configured
- [ ] `.env` is in `.gitignore`
- [ ] JWT_SECRET is 32+ characters
- [ ] APP_ENV=production
- [ ] APP_DEBUG=false
- [ ] CORS_ORIGIN set to your domain (not `*`)
- [ ] Database password is strong (12+ chars)
- [ ] `/logs/` directory exists and is writable
- [ ] Error logging enabled
- [ ] SSL/HTTPS configured on web server
- [ ] Database backups automated
- [ ] Rate limiting implemented (optional)
- [ ] Firewall rules configured

---

## 🔍 Known Vulnerabilities Fixed

| Issue | Severity | Fix |
|-------|----------|-----|
| SQL Injection in `offres.php` | **CRITICAL** | Whitelist validation + prepared statements |
| Hardcoded JWT secret | **CRITICAL** | Moved to `.env` |
| CORS open to `*` | **HIGH** | Configurable in `.env` |
| No password validation | **MEDIUM** | 8+ character minimum |
| Insufficient bcrypt cost | **MEDIUM** | Increased to cost=12 |
| No authorization checks | **HIGH** | Added ownership verification |
| Errors exposing internals | **MEDIUM** | Generic messages in production |
| No logging | **MEDIUM** | Added error + auth logging |

---

## 🛡️ Best Practices for Developers

### 1. **Always use prepared statements**
```php
// ❌ NEVER
$db->query("SELECT * FROM users WHERE id = $id");

// ✅ ALWAYS
$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
```

### 2. **Validate & whitelist user input**
```php
if (!in_array($type, ['offres', 'missions'])) {
    jsonError('Invalid type');
}
```

### 3. **Check authorization**
```php
if ($user_id != $record['owner_id']) {
    jsonError('Unauthorized', 403);
}
```

### 4. **Use transactions for complex operations**
```php
$db->beginTransaction();
try {
    // multiple operations
    $db->commit();
} catch (Exception $e) {
    $db->rollBack();
    log_error($e->getMessage());
}
```

### 5. **Never expose sensitive data**
```php
// ❌ NEVER
jsonError('User not found: ' . $e->getMessage(), 500);

// ✅ ALWAYS
log_error('Query failed: ' . $e->getMessage());
jsonError('Server error', 500);
```

---

## 📞 Security Incidents

If you discover a vulnerability:
1. Do NOT create a public issue
2. Email: `security@talentlink.com`
3. Include reproduction steps
4. Include potential impact

---

## 📚 References

- [OWASP Top 10](https://owasp.org/www-project-top-ten/)
- [PHP Security Guide](https://www.php.net/manual/en/security.php)
- [JWT Best Practices](https://tools.ietf.org/html/rfc7519)
- [Password Storage](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html)
