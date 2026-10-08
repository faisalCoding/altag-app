<?php

namespace App\Support;

class UserGuideContent
{
    /**
     * @return array<int, array{heading: string, pages: array<int, array{title: string, route: string, icon: string, description: string}>}>
     */
    public static function for(string $guard): array
    {
        return match ($guard) {
            'manager' => self::manager(),
            'supervisor' => self::supervisor(),
            'teacher' => self::teacher(),
            'student' => self::student(),
            'guardian' => self::guardian(),
            default => [],
        };
    }

    private static function manager(): array
    {
        return [
            [
                'heading' => 'الرئيسية',
                'pages' => [
                    ['title' => 'لوحة التحكم', 'route' => 'manager.dashboard', 'icon' => 'home', 'description' => 'نظرة عامة سريعة على حالة المجمع كله: عدد الطلاب والمعلمين والحلقات وأهم المؤشرات.'],
                    ['title' => 'الرسائل', 'route' => 'manager.messages', 'icon' => 'envelope', 'description' => 'محادثات مباشرة معك ومع أي مستخدم في المجمع — بصفتك المدير تقدر تراسل الجميع.'],
                ],
            ],
            [
                'heading' => 'المستخدمين',
                'pages' => [
                    ['title' => 'المراحل التعليمية', 'route' => 'manager.stages', 'icon' => 'rectangle-stack', 'description' => 'إنشاء وتعديل المراحل الدراسية (مثل المرحلة الأولى، الثانية...) وتوزيع المشرفين عليها.'],
                    ['title' => 'الحلقات', 'route' => 'manager.circles', 'icon' => 'circle-stack', 'description' => 'إدارة حلقات التحفيظ: إنشاء حلقة جديدة، ربطها بمرحلة، وتعيين المعلمين المسؤولين عنها.'],
                    ['title' => 'المشرفون', 'route' => 'manager.supervisors', 'icon' => 'users', 'description' => 'إدارة حسابات المشرفين وربط كل مشرف بالمراحل التي يشرف عليها.'],
                    ['title' => 'المعلمون', 'route' => 'manager.teachers', 'icon' => 'users', 'description' => 'إدارة حسابات المعلمين، صلاحياتهم، والحلقات المسندة لكل معلم.'],
                    ['title' => 'الطلاب', 'route' => 'manager.students', 'icon' => 'academic-cap', 'description' => 'إدارة بيانات الطلاب: الحلقة، الحالة، ولي الأمر، وتاريخ الالتحاق.'],
                    ['title' => 'الأوصياء', 'route' => 'manager.guardians', 'icon' => 'user-group', 'description' => 'إدارة حسابات أولياء الأمور وربطهم بأبنائهم الطلاب.'],
                    ['title' => 'طلبات التسجيل', 'route' => 'manager.pending-approvals', 'icon' => 'user-plus', 'description' => 'الموافقة أو الرفض لأي حساب جديد سجّل نفسه ذاتيًا من صفحة التسجيل العامة، وتحديد نوع حسابه الحقيقي (طالب، معلم...).'],
                ],
            ],
            [
                'heading' => 'الاختبارات',
                'pages' => [
                    ['title' => 'مستويات الاختبارات', 'route' => 'manager.exam-levels', 'icon' => 'document-text', 'description' => 'تعريف مستويات الاختبارات المختلفة (مثل: جزء عمّ، جزء تبارك...) المستخدمة عند جدولة اختبار لطالب.'],
                    ['title' => 'اختبارات الطلاب', 'route' => 'manager.student-exams', 'icon' => 'academic-cap', 'description' => 'عرض كل الاختبارات المجدولة والمنجزة لجميع الطلاب، بنتائجها وحالتها.'],
                ],
            ],
            [
                'heading' => 'التقارير',
                'pages' => [
                    ['title' => 'تقارير الحضور والغياب', 'route' => 'manager.attendance-reports', 'icon' => 'chart-bar-square', 'description' => 'تقرير مفصّل بحضور وغياب الطلاب حسب الفترة والحلقة.'],
                    ['title' => 'متابعة الحلقات السنوي', 'route' => 'manager.yearly-attendance', 'icon' => 'calendar', 'description' => 'نظرة سنوية شاملة على مدى التزام كل حلقة بتسجيل الحضور شهرًا بشهر.'],
                    ['title' => 'التقويم الأكاديمي', 'route' => 'manager.academic-calendar', 'icon' => 'calendar-days', 'description' => 'إضافة إجازات ومناسبات وفترات اختبارات، وتحديد أيام الدوام الفعلية المستخدمة في حسابات الحضور.'],
                    ['title' => 'المهام', 'route' => 'manager.tasks', 'icon' => 'clipboard-document-list', 'description' => 'قائمة مهامك الشخصية كمدير، بتواريخ استحقاق هجرية، وربطها بمناسبات التقويم عند الحاجة.'],
                    ['title' => 'تقرير الإنجاز القرآني', 'route' => 'manager.quranic-achievement', 'icon' => 'document-chart-bar', 'description' => 'تقرير شامل لمجموع ما تم حفظه ومراجعته من القرآن على مستوى المجمع كله خلال فترة معينة، وأفضل الطلاب إنجازًا.'],
                    ['title' => 'لائحة التجاوزات', 'route' => 'manager.exceeded-limits', 'icon' => 'exclamation-triangle', 'description' => 'قائمة الطلاب الذين تجاوزوا الحد المسموح من الغياب أو التأخير، لمتابعتهم مع أولياء أمورهم.'],
                ],
            ],
            [
                'heading' => 'التحليل الذكي',
                'pages' => [
                    ['title' => 'التحليل الذكي', 'route' => 'manager.ai-analysis', 'icon' => 'sparkles', 'description' => 'تحليل مبني على الذكاء الاصطناعي لأنماط الحضور والأداء، لاكتشاف اتجاهات قد لا تظهر من الأرقام العادية.'],
                ],
            ],
            [
                'heading' => 'بيانات المصحف',
                'pages' => [
                    ['title' => 'محرر الأسطر', 'route' => 'manager.quran-editor', 'icon' => 'book-open', 'description' => 'أداة فنية لتصحيح بيانات الأسطر والصفحات لكل آية في المصحف — تُستخدم فقط عند وجود خطأ في بيانات الحفظ الأساسية.'],
                ],
            ],
            [
                'heading' => 'إدارة النظام',
                'pages' => [
                    ['title' => 'إعدادات الانضباط', 'route' => 'manager.settings', 'icon' => 'cog', 'description' => 'تحديد حدود الغياب والتأخير المسموحة، ومدة احتساب الفترة، وأدوات النسخ الاحتياطي لقاعدة البيانات.'],
                    ['title' => 'إعدادات الواتساب', 'route' => 'manager.whatsapp-settings', 'icon' => 'chat-bubble-left-right', 'description' => 'ربط رقم واتساب بالنظام (بمسح رمز QR) لإرسال إشعارات الغياب والتأخير تلقائيًا لأولياء الأمور.'],
                    ['title' => 'صلاحيات الصفحات', 'route' => 'manager.role-permissions', 'icon' => 'shield-check', 'description' => 'تفعيل أو تعطيل أي صفحة لأي دور — الصفحة المعطّلة تختفي من القائمة الجانبية ولا يقدر أحد يدخلها حتى بالرابط المباشر.'],
                    ['title' => 'توثيق الـ API', 'route' => 'manager.api-docs', 'icon' => 'document-text', 'description' => 'توثيق تقني لواجهات البرمجة المستخدمة من تطبيقات خارجية — مخصص للمطورين وليس للاستخدام اليومي.'],
                    ['title' => 'نسخ احتياطية', 'route' => 'manager.backup-browser', 'icon' => 'archive-box', 'description' => 'تصفح نسخة احتياطية قديمة واستعادة سجلات محددة منها إلى قاعدة البيانات الحالية عند فقدان بيانات بالخطأ.'],
                ],
            ],
        ];
    }

    private static function supervisor(): array
    {
        return [
            [
                'heading' => 'الرئيسية',
                'pages' => [
                    ['title' => 'لوحة التحكم', 'route' => 'supervisor.dashboard', 'icon' => 'home', 'description' => 'نظرة سريعة على مراحلك وحلقاتك ومعلميك وطلابك، ونسبة الحضور الأسبوعية.'],
                    ['title' => 'الرسائل', 'route' => 'supervisor.messages', 'icon' => 'envelope', 'description' => 'محادثات مباشرة مع المعلمين والطلاب وأولياء الأمور ضمن نطاق إشرافك، والمدير.'],
                ],
            ],
            [
                'heading' => 'العملية التعليمية',
                'pages' => [
                    ['title' => 'الحلقات', 'route' => 'supervisor.circles', 'icon' => 'circle-stack', 'description' => 'إدارة الحلقات الواقعة ضمن المراحل التي تشرف عليها.'],
                    ['title' => 'الطلاب', 'route' => 'supervisor.students', 'icon' => 'academic-cap', 'description' => 'عرض وإدارة طلاب حلقات مرحلتك، مع إجراءات جماعية (تغيير حلقة، تغيير حالة...).'],
                    ['title' => 'المعلمون', 'route' => 'supervisor.teachers', 'icon' => 'users', 'description' => 'إدارة شؤون المعلمين في نطاق إشرافك، صلاحياتهم، والموافقة على حساباتهم.'],
                ],
            ],
            [
                'heading' => 'المحتوى العلمي',
                'pages' => [
                    ['title' => 'إدارة المنظومات', 'route' => 'supervisor.odes', 'icon' => 'book-open', 'description' => 'إضافة المنظومات العلمية والشعرية وأبياتها.'],
                    ['title' => 'مسارات حفظ المنظومات', 'route' => 'supervisor.odes.paths', 'icon' => 'map', 'description' => 'تعريف مسارات منهجية لحفظ المنظومات وتسكين الطلاب فيها.'],
                    ['title' => 'خطط المنظومات المنشأة', 'route' => 'supervisor.odes.plans', 'icon' => 'clipboard-document-list', 'description' => 'عرض خطط حفظ المنظومات التي تم إنشاؤها للطلاب.'],
                    ['title' => 'إدارة الأحاديث', 'route' => 'supervisor.hadiths', 'icon' => 'document-text', 'description' => 'إضافة الأحاديث النبوية وأسانيدها ومتونها.'],
                    ['title' => 'مسارات حفظ المتون', 'route' => 'supervisor.hadiths.paths', 'icon' => 'map', 'description' => 'تعريف مسارات منهجية لحفظ متون الأحاديث وتسكين الطلاب فيها.'],
                ],
            ],
            [
                'heading' => 'التلعيب والمسابقات',
                'pages' => [
                    ['title' => 'المسابقات', 'route' => 'supervisor.competitions', 'icon' => 'trophy', 'description' => 'إنشاء مسابقة تقليدية (نقاط وترتيب) أو مسابقة تلعيب متكاملة (مستويات، فرق، متجر، أوسمة)، وتحديد الحلقات المشاركة.'],
                    ['title' => 'عرض الأوائل', 'route' => 'supervisor.competitions.standings', 'icon' => 'trophy', 'description' => 'عرض أفضل الطلاب ترتيبًا في كل مسار من مسارات مسابقة معينة.'],
                ],
            ],
            [
                'heading' => 'المتابعة والتقارير',
                'pages' => [
                    ['title' => 'متابعة الحلقات السنوي', 'route' => 'supervisor.yearly-attendance', 'icon' => 'calendar', 'description' => 'نظرة سنوية على مدى التزام حلقاتك بتسجيل الحضور.'],
                    ['title' => 'التقويم الأكاديمي', 'route' => 'supervisor.academic-calendar', 'icon' => 'calendar-days', 'description' => 'عرض ومتابعة مناسبات وإجازات التقويم الأكاديمي الخاصة بنطاقك.'],
                    ['title' => 'المهام', 'route' => 'supervisor.tasks', 'icon' => 'clipboard-document-list', 'description' => 'قائمة مهامك الشخصية كمشرف، بتواريخ استحقاق هجرية.'],
                    ['title' => 'لائحة التجاوزات', 'route' => 'supervisor.exceeded-limits', 'icon' => 'exclamation-triangle', 'description' => 'الطلاب الذين تجاوزوا حدود الغياب أو التأخير ضمن نطاق إشرافك.'],
                ],
            ],
            [
                'heading' => 'إدارة النظام',
                'pages' => [
                    ['title' => 'إعدادات الواتساب', 'route' => 'supervisor.whatsapp-settings', 'icon' => 'chat-bubble-left-right', 'description' => 'ربط رقم واتساب خاص بإشرافك لإرسال إشعارات الغياب لأولياء الأمور.'],
                    ['title' => 'إدارة النماذج', 'route' => 'supervisor.forms', 'icon' => 'document-text', 'description' => 'بناء نماذج واستمارات مخصصة لجمع البيانات أو التسجيل، ومراجعة الردود الواردة وتحويلها لحسابات طلاب.'],
                ],
            ],
        ];
    }

    private static function teacher(): array
    {
        return [
            [
                'heading' => 'الرئيسية',
                'pages' => [
                    ['title' => 'لوحة التحكم', 'route' => 'teacher.dashboard', 'icon' => 'home', 'description' => 'نظرة سريعة على حلقتك: عدد الطلاب، المهام المعلّقة، وأهم التنبيهات.'],
                    ['title' => 'الرسائل', 'route' => 'teacher.messages', 'icon' => 'envelope', 'description' => 'محادثات مباشرة مع طلابك وأولياء أمورهم ومشرفك.'],
                ],
            ],
            [
                'heading' => 'الخطط القرآنية',
                'pages' => [
                    ['title' => 'إدارة الطلاب', 'route' => 'teacher.students', 'icon' => 'users', 'description' => 'عرض قائمة طلاب حلقتك وبياناتهم الأساسية.'],
                    ['title' => 'إنشاء خطة طالب', 'route' => 'teacher.plan-creator', 'icon' => 'pencil-square', 'description' => 'بناء خطة حفظ ومراجعة قرآنية جديدة لطالب معين: نطاق الحفظ، عدد الأيام، واتجاه الخطة.'],
                    ['title' => 'عرض الخطط المنشأة', 'route' => 'teacher.student-plans', 'icon' => 'clipboard-document-list', 'description' => 'قائمة كل خطط الحفظ التي أنشأتها لطلابك، مع إمكانية اعتمادها أو طباعتها أو تنزيلها PDF.'],
                    ['title' => 'التسميع والمتابعة', 'route' => 'teacher.tasmeeh', 'icon' => 'book-open', 'description' => 'الصفحة اليومية لتسميع الطلاب: تسجيل تقييم الحفظ والمراجعة (ممتاز/جيد/مقبول/لم يُسمّع) لكل طالب أولاً بأول.'],
                    ['title' => 'التسميع المتبادل', 'route' => 'teacher.pairs', 'icon' => 'users', 'description' => 'لليوم المحدد، يقترح النظام تلقائيًا أزواج طلاب حاضرين يسمّع كل منهم للآخر، حسب تطابق مراجعة أحدهم مع حفظ الآخر.'],
                ],
            ],
            [
                'heading' => 'خطط المنظومات',
                'pages' => [
                    ['title' => 'عرض الخطط المنشأة', 'route' => 'teacher.ode-plans', 'icon' => 'clipboard-document-list', 'description' => 'قائمة خطط حفظ المنظومات العلمية والشعرية الخاصة بطلابك.'],
                ],
            ],
            [
                'heading' => 'التحفيز والمنافسة',
                'pages' => [
                    ['title' => 'مسابقات الحلقة', 'route' => 'teacher.leaderboards', 'icon' => 'trophy', 'description' => 'عرض المسابقات النشطة المرتبطة بحلقتك.'],
                    ['title' => 'رصد الدرجات', 'route' => 'teacher.grade-items', 'icon' => 'star', 'description' => 'تسجيل نقاط/درجات الطلاب في المسابقة النشطة حاليًا حسب بنود التقييم المحددة لها.'],
                ],
            ],
            [
                'heading' => 'الاختبارات',
                'pages' => [
                    ['title' => 'اختبارات الطلاب', 'route' => 'teacher.student-exams', 'icon' => 'academic-cap', 'description' => 'جدولة اختبار جديد لطالب وتسجيل نتيجته، أو مراجعة الاختبارات السابقة.'],
                ],
            ],
            [
                'heading' => 'التحضير',
                'pages' => [
                    ['title' => 'سجل الحضور', 'route' => 'teacher.attendance', 'icon' => 'calendar', 'description' => 'تسجيل حضور طلاب الحلقة يوميًا (حاضر/غائب/متأخر/مستأذن)، بوضعين: تحضير تفاعلي سريع أو قائمة يدوية كاملة.'],
                    ['title' => 'الانضباط الحضوري', 'route' => 'teacher.discipline', 'icon' => 'chart-bar', 'description' => 'إحصائية حضور وغياب كل طالب خلال فترة مختارة، مع قائمة بالأكثر تكرارًا في الغياب أو التأخير.'],
                    ['title' => 'الانضباط القرآني', 'route' => 'teacher.quranic-discipline', 'icon' => 'chart-pie', 'description' => 'إحصائية تقييمات الحفظ والمراجعة لكل طالب، لمعرفة من يحتاج متابعة أكثر في التسميع.'],
                    ['title' => 'لائحة التجاوزات', 'route' => 'teacher.exceeded-limits', 'icon' => 'exclamation-triangle', 'description' => 'طلابك الذين تجاوزوا حد الغياب أو التأخير المسموح.'],
                ],
            ],
        ];
    }

    private static function student(): array
    {
        return [
            [
                'heading' => 'الرئيسية',
                'pages' => [
                    ['title' => 'لوحة التحكم', 'route' => 'student.dashboard', 'icon' => 'home', 'description' => 'مهمة اليوم، تقدّمك في الحفظ، وأهم إنجازاتك في مكان واحد.'],
                    ['title' => 'الرسائل', 'route' => 'student.messages', 'icon' => 'envelope', 'description' => 'محادثة مباشرة مع معلمك أو ولي أمرك.'],
                ],
            ],
            [
                'heading' => 'مساري القرآني',
                'pages' => [
                    ['title' => 'مساري القرآني', 'route' => 'student.plan', 'icon' => 'book-open', 'description' => 'عرض خطتك الحالية في الحفظ والمراجعة يومًا بيوم.'],
                ],
            ],
            [
                'heading' => 'المتابعة',
                'pages' => [
                    ['title' => 'الاختبارات', 'route' => 'student.exams', 'icon' => 'academic-cap', 'description' => 'اختباراتك القادمة ونتائج اختباراتك السابقة.'],
                    ['title' => 'سجل الانضباط', 'route' => 'student.attendance', 'icon' => 'clipboard-document-check', 'description' => 'سجل حضورك وغيابك خلال الشهر.'],
                    ['title' => 'التقارير', 'route' => 'student.reports', 'icon' => 'chart-bar-square', 'description' => 'تقرير شامل عن تقدّمك في الحفظ والمراجعة عبر الوقت.'],
                ],
            ],
        ];
    }

    private static function guardian(): array
    {
        return [
            [
                'heading' => 'الرئيسية',
                'pages' => [
                    ['title' => 'لوحة التحكم', 'route' => 'guardian.dashboard', 'icon' => 'home', 'description' => 'نظرة عامة على كل أبنائك المسجّلين: مهمة اليوم، آخر تقييم، ونسبة الحضور لكل واحد منهم.'],
                    ['title' => 'الرسائل', 'route' => 'guardian.messages', 'icon' => 'envelope', 'description' => 'محادثة مباشرة مع معلم ابنك.'],
                    ['title' => 'المكافآت التحفيزية', 'route' => 'guardian.challenges', 'icon' => 'trophy', 'description' => 'وضع تحدٍّ أو مكافأة تحفيزية لابنك مقابل إنجاز معيّن في الحفظ.'],
                ],
            ],
            [
                'heading' => 'متابعة الأبناء',
                'pages' => [
                    ['title' => 'صفحة الابن', 'route' => 'guardian.student', 'icon' => 'academic-cap', 'description' => 'تفاصيل تقدّم ابنك: نسبة المحفوظ، مهمة اليوم، الخطة الحالية، وآخر التقييمات — لكل ابن صفحته الخاصة من القائمة الجانبية.'],
                    ['title' => 'سجل حضور الابن', 'route' => 'guardian.student.attendance', 'icon' => 'calendar', 'description' => 'تقويم شهري يوضّح أيام حضور وغياب وتأخير ابنك.'],
                ],
            ],
        ];
    }
}
