'use strict';

/**
 * كل جلسة واتساب هنا هي متصفّح Chromium كامل يشغّل WhatsApp Web، بذاكرته
 * ومعالجته المستمرة. فالسجل التالي معنيّ بأمر واحد: ألا يعمل متصفّح إلا وله
 * سبب، وأن يُغلق فعلاً حين يزول السبب.
 *
 * منفصل عن الخادم ليكون قابلاً للاختبار دون تشغيل متصفّح: يتلقى مصنع العميل
 * والساعة من الخارج.
 */
class SessionRegistry {
    /**
     * @param {object} options
     * @param {(clientId: string) => object} options.createClient مصنع عميل واتساب
     * @param {number} [options.idleMs] مهلة الخمول قبل إغلاق الجلسة تلقائياً
     * @param {() => number} [options.now] مصدر الوقت، يُحقن في الاختبارات
     * @param {(...args: unknown[]) => void} [options.log]
     */
    constructor({ createClient, idleMs = 30 * 60 * 1000, now = Date.now, log = console.log }) {
        this.createClient = createClient;
        this.idleMs = idleMs;
        this.now = now;
        this.log = log;
        this.sessions = new Map();
    }

    /**
     * الجلسة كما هي، دون إنشاء. القراءة لا توقظ متصفّحاً.
     */
    get(clientId) {
        return this.sessions.get(clientId);
    }

    has(clientId) {
        return this.sessions.has(clientId);
    }

    get size() {
        return this.sessions.size;
    }

    /**
     * تشغيل جلسة إن لم تكن تعمل. النداء المتكرر لا ينشئ متصفّحاً ثانياً.
     */
    start(clientId) {
        const existing = this.sessions.get(clientId);

        if (existing) {
            existing.lastUsedAt = this.now();

            return existing;
        }

        const session = {
            client: null,
            status: 'starting',
            qrCode: null,
            loadingPercent: 0,
            lastUsedAt: this.now(),
        };

        const client = this.createClient(clientId);
        this.bind(clientId, session, client);

        session.client = client;
        this.sessions.set(clientId, session);

        client.initialize();

        return session;
    }

    /**
     * ربط أحداث العميل بحالة الجلسة.
     */
    bind(clientId, session, client) {
        client.on('qr', (qr) => {
            this.log(`[${clientId}] QR Code جديد.`);
            session.qrCode = qr;
            session.status = 'needs_scan';
        });

        client.on('loading_screen', (percent) => {
            session.loadingPercent = percent;
            session.status = 'loading';
        });

        client.on('authenticated', () => {
            this.log(`[${clientId}] تمت المصادقة.`);
            session.status = 'loading';
            session.qrCode = null;
        });

        client.on('ready', () => {
            this.log(`[${clientId}] جاهز للإرسال.`);
            session.status = 'ready';
            session.qrCode = null;
            session.lastUsedAt = this.now();
        });

        // الإغلاق هنا هو بيت الداء السابق: كانت الجلسة تُحذف من الخريطة دون
        // إنهاء المتصفّح، فيبقى Chromium يتيماً يعمل إلى الأبد بينما يُفتح
        // غيره عند أول طلب. كل انقطاع شبكة كان يترك عملية زائدة.
        client.on('disconnected', (reason) => {
            this.log(`[${clientId}] قُطع الاتصال: ${reason}`);
            session.status = 'disconnected';
            session.qrCode = null;
            this.stop(clientId);
        });
    }

    /**
     * إنهاء المتصفّح وإزالة الجلسة. آمنة للنداء على جلسة غير موجودة.
     */
    async stop(clientId) {
        const session = this.sessions.get(clientId);

        if (!session) {
            return false;
        }

        this.sessions.delete(clientId);

        try {
            await session.client?.destroy();
        } catch (error) {
            this.log(`[${clientId}] تعذّر إنهاء المتصفّح: ${error.message}`);
        }

        return true;
    }

    /**
     * تسجيل استعمال، فلا تُغلق جلسة تُستخدم فعلاً.
     */
    touch(clientId) {
        const session = this.sessions.get(clientId);

        if (session) {
            session.lastUsedAt = this.now();
        }
    }

    /**
     * إغلاق الجلسات التي لم تُستعمل منذ مدة الخمول.
     *
     * الإرسال يوقظها من جديد، فثمن الإغلاق تأخّر أول رسالة لا ضياعها — وهو ثمن
     * زهيد مقابل متصفّح يعمل ليلاً ونهاراً لمستخدم لا يرسل إلا مرتين في الأسبوع.
     *
     * @returns {Promise<string[]>} معرّفات ما أُغلق
     */
    async sweepIdle() {
        const cutoff = this.now() - this.idleMs;
        const stale = [];

        for (const [clientId, session] of this.sessions) {
            if (session.lastUsedAt <= cutoff) {
                stale.push(clientId);
            }
        }

        for (const clientId of stale) {
            this.log(`[${clientId}] إغلاق لخمول.`);
            await this.stop(clientId);
        }

        return stale;
    }

    /**
     * إنهاء كل شيء — عند إيقاف الخدمة.
     */
    async stopAll() {
        for (const clientId of [...this.sessions.keys()]) {
            await this.stop(clientId);
        }
    }
}

module.exports = { SessionRegistry };
