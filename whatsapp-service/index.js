const express = require('express');
const qrcode = require('qrcode');
const { Client, LocalAuth } = require('whatsapp-web.js');
const fs = require('fs');
const path = require('path');

const app = express();
app.use(express.json());

// الخدمة داخلية فقط: يستقبل الطلبات من Laravel على نفس الخادم، لذا نربط على
// localhost حصراً ولا حاجة لـ CORS (لا يوجد اتصال من المتصفح مباشرة).
const port = parseInt(process.env.WHATSAPP_PORT || '3000', 10);
const host = process.env.WHATSAPP_BIND_HOST || '127.0.0.1';

// مفتاح سري مشترك مع Laravel: إذا عُرّف WHATSAPP_API_KEY تُرفض أي طلبات بدونه.
const apiKey = process.env.WHATSAPP_API_KEY || '';
app.use((req, res, next) => {
    if (apiKey && req.get('x-api-key') !== apiKey) {
        return res.status(401).json({ success: false, message: 'غير مصرح.' });
    }
    next();
});

const { SessionRegistry } = require('./sessions');

// مهلة الخمول: بعدها تُغلق الجلسة ويُحرَّر متصفّحها. الإرسال يوقظها من جديد.
const idleMs = parseInt(process.env.WHATSAPP_IDLE_MINUTES || '30', 10) * 60 * 1000;
const sweepMs = 60 * 1000;

const registry = new SessionRegistry({
    idleMs,
    createClient: (clientId) => new Client({
        authStrategy: new LocalAuth({ clientId }),
        puppeteer: {
            headless: true,
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                // crashpad يتطلب HOME قابلاً للكتابة ويفشل تحت systemd؛ لا حاجة له.
                '--disable-crashpad',
                '--disable-gpu',
                // لا نعرض صفحات لأحد: كل ما يخدم واجهة مستخدم أو تحديثاً خلفياً
                // هو معالجة مهدورة على خادم يشارك المعالج مع الموقع.
                '--disable-extensions',
                '--disable-background-networking',
                '--disable-default-apps',
                '--disable-sync',
                '--no-first-run',
                '--mute-audio',
                '--renderer-process-limit=1',
            ],
        },
    }),
});

setInterval(() => {
    registry.sweepIdle().catch((error) => console.error('[sweepIdle]', error));
}, sweepMs).unref();

// ── GET /status/:clientId ─────────────────────────────────────────────────────
app.get('/status/:clientId', async (req, res) => {
    const { clientId } = req.params;
    const session = registry.get(clientId);

    // القراءة لا تُشغّل متصفّحاً. كان أي استعلام حالة — ولو بمعرّف مكتوب خطأً —
    // يفتح Chromium كاملاً ويتركه يعمل.
    if (!session) {
        return res.json({ status: 'stopped', message: 'الجلسة متوقفة. اضغط «ربط الواتساب» لبدئها.' });
    }

    if (session.status === 'ready') {
        return res.json({ status: 'ready', message: 'واتساب متصل وجاهز.' });
    }

    if (session.status === 'loading') {
        return res.json({
            status: 'loading',
            message: `تمت المصادقة. جاري التهيئة... (${session.loadingPercent}%)`,
        });
    }

    if (session.status === 'needs_scan' && session.qrCode) {
        try {
            const qrImage = await qrcode.toDataURL(session.qrCode);
            return res.json({ status: 'needs_scan', qr_image: qrImage });
        } catch (err) {
            return res.status(500).json({ status: 'error', message: 'فشل في توليد QR.' });
        }
    }

    if (session.status === 'disconnected') {
        return res.json({ status: 'disconnected', message: 'انقطع الاتصال. جاري إعادة التهيئة...' });
    }

    return res.json({ status: 'starting', message: 'جاري تهيئة الواتساب...' });
});

// ── POST /connect/:clientId ───────────────────────────────────────────────────
// بدء الجلسة صار فعلاً صريحاً: يطلبه المستخدم من صفحة الإعدادات، أو يوقظه
// الإرسال عند الحاجة. لا شيء آخر يفتح متصفّحاً.
app.post('/connect/:clientId', (req, res) => {
    const { clientId } = req.params;
    const session = registry.start(clientId);

    return res.json({ success: true, status: session.status });
});

// ── POST /send ────────────────────────────────────────────────────────────────
app.post('/send', async (req, res) => {
    const { clientId, phone, message } = req.body;

    if (!clientId || !phone || !message) {
        return res.status(400).json({ success: false, message: 'يرجى توفير clientId ورقم الهاتف والرسالة.' });
    }

    const session = registry.get(clientId);

    // جلسة نائمة تُوقَظ هنا ويُرَدّ 503: الإرسال يعيد المحاولة بعد أن يسخن
    // المتصفّح. هذا ما يسمح بإغلاق الجلسات الخاملة دون فقد رسالة.
    if (!session) {
        registry.start(clientId);

        return res.status(503).json({
            success: false,
            status: 'starting',
            retryable: true,
            message: `الجلسة [${clientId}] كانت متوقفة، وبدأ تشغيلها. أعد المحاولة بعد قليل.`,
        });
    }

    if (session.status !== 'ready') {
        return res.status(503).json({
            success: false,
            status: session.status,
            retryable: true,
            message: `الجلسة [${clientId}] غير جاهزة بعد.`,
        });
    }

    try {
        const chatId = `${phone}@c.us`;
        await session.client.sendMessage(chatId, message);
        registry.touch(clientId);

        return res.json({ success: true, message: 'تم الإرسال بنجاح.' });
    } catch (error) {
        console.error(`[${clientId}] خطأ في الإرسال:`, error.message);
        return res.status(500).json({ success: false, message: 'حدث خطأ أثناء الإرسال.', error: error.message });
    }
});

// ── POST /disconnect/:clientId ────────────────────────────────────────────────
app.post('/disconnect/:clientId', async (req, res) => {
    const { clientId } = req.params;

    await registry.stop(clientId);

    return res.json({ success: true, message: 'تم قطع الاتصال.' });
});

// ── POST /reset/:clientId ────────────────────────────────────────────────
app.post('/reset/:clientId', async (req, res) => {
    const { clientId } = req.params;

    await registry.stop(clientId);

    const sessionDir = path.join(__dirname, '.wwebjs_auth', `session-${clientId}`);
    if (fs.existsSync(sessionDir)) {
        try {
            fs.rmSync(sessionDir, { recursive: true, force: true });
        } catch (e) {
            console.error('Error deleting session directory:', e);
        }
    }

    return res.json({ success: true, message: 'تم إعادة تعيين الجلسة بنجاح.' });
});

// whatsapp-web.js تُصدر أحياناً أخطاء Promise غير معالجة (انقطاع جلسة، تغيّر
// واجهة واتساب ويب). نسجلها ونبقي الخدمة حية بدل أن يُنهي Node العملية.
process.on('unhandledRejection', (reason) => {
    console.error('[unhandledRejection]', reason);
});

// خطأ متزامن غير متوقع يترك العملية بحالة غير موثوقة: نسجله ونخرج،
// وsystemd (Restart=always) يعيد التشغيل خلال ثوانٍ.
process.on('uncaughtException', (err) => {
    console.error('[uncaughtException]', err);
    process.exit(1);
});

// لا تُستعاد الجلسات عند الإقلاع.
//
// كانت الخدمة تفتح متصفّحاً لكل مجلد في .wwebjs_auth — مستعملاً كان أو مهجوراً،
// وحتى المجلدات التي خلّفها خطأ في الطرفية — فتبدأ بعدة نسخ من Chromium لا
// يطلبها أحد. الجلسة الآن تبدأ حين تُطلب: من صفحة الإعدادات، أو من أول رسالة
// تُرسَل، وتُعيد المحاولة حتى تسخن.

// إنهاء المتصفحات عند إيقاف الخدمة، فلا تبقى معلّقة بعد رحيل العملية الأم.
['SIGTERM', 'SIGINT'].forEach((signal) => {
    process.on(signal, async () => {
        console.log(`استلمت ${signal}: إغلاق الجلسات...`);
        await registry.stopAll();
        process.exit(0);
    });
});

app.listen(port, host, () => {
    console.log(`خدمة الواتساب تعمل على http://${host}:${port}`);
    console.log(`تُغلق الجلسة الخاملة بعد ${idleMs / 60000} دقيقة.`);
});
