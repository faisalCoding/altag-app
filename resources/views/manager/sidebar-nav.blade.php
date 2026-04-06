        <flux:sidebar.item icon="home" :href="route('manager.dashboard')"
            :current="request()->routeIs('manager.dashboard')" wire:navigate>
            الرئيسية
        </flux:sidebar.item>
            <flux:sidebar.item icon="rectangle-stack" :href="route('manager.stages')"
            :current="request()->routeIs('manager.stages')" wire:navigate>
            المراحل التعليمية
        </flux:sidebar.item>
        <flux:sidebar.item icon="circle-stack" :href="route('manager.circles')"
            :current="request()->routeIs('manager.circles')" wire:navigate>
            الحلقات
        </flux:sidebar.item>
        <flux:sidebar.group expandable expanded="false" heading="المستخدمين" class="grid">


        <flux:sidebar.item icon="users" :href="route('manager.teachers')"
            :current="request()->routeIs('manager.teachers')" wire:navigate>
            المعلمون
        </flux:sidebar.item>
        <flux:sidebar.item icon="academic-cap" :href="route('manager.students')"
            :current="request()->routeIs('manager.students')" wire:navigate>
            الطلاب
        </flux:sidebar.item>
        <flux:sidebar.item icon="user-group" :href="route('manager.guardians')"
            :current="request()->routeIs('manager.guardians')" wire:navigate>
            الأوصياء
        </flux:sidebar.item>
        </flux:sidebar.group>
         <flux:sidebar.group expandable expanded="false" heading="التقارير" class="grid">
            <flux:sidebar.item icon="chart-bar-square" :href="route('manager.attendance-reports')"
                :current="request()->routeIs('manager.attendance-reports')" wire:navigate>
                تقارير الحضور والغياب
            </flux:sidebar.item>
            <flux:sidebar.item icon="calendar" :href="route('manager.yearly-attendance')"
                :current="request()->routeIs('manager.yearly-attendance')" wire:navigate>
                متابعة الحلقات السنوي
            </flux:sidebar.item>
        </flux:sidebar.group>
    <flux:sidebar.group expandable expanded="false" heading="التحليل" class="grid">
        <flux:sidebar.item icon="sparkles" :href="route('manager.ai-analysis')"
            :current="request()->routeIs('manager.ai-analysis')" wire:navigate>
            الالتحليل الذكي
        </flux:sidebar.item>
    </flux:sidebar.group>

    <flux:sidebar.group expandable expanded="false" heading="بيانات المصحف" class="grid">
        <flux:sidebar.item icon="book-open" :href="route('manager.quran-editor')"
            :current="request()->routeIs('manager.quran-editor')" wire:navigate>
            محرر الأسطر
        </flux:sidebar.item>
    </flux:sidebar.group>
