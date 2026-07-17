<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="text-center mb-4">
    <h2 class="h4">Frequently asked questions</h2>
    <p class="text-secondary">Everything you need to know about the platform.</p>
    <div class="input-group mx-auto" style="max-width:420px"><span class="input-group-text"><i class="bi bi-search"></i></span><input class="form-control" placeholder="Search the help center…"></div>
</div>

<div class="row g-3 justify-content-center"><div class="col-lg-8">
    <div class="accordion" id="faqAcc">
        <?php
        $faqs = [
            ['How do I register as a vendor?', 'Sign up, complete your business profile, and submit your GSTIN. Once verified, you can create shops and list products.'],
            ['When are settlements paid out?', 'Settlements run on your configured cycle (daily/weekly/monthly). Funds are transferred to your registered bank account after deductions.'],
            ['How is GST calculated?', 'GST is computed per HSN code and can be inclusive or exclusive. Tax invoices are generated automatically for each order.'],
            ['Can I run multiple shops?', 'Yes. Depending on your plan, you can operate multiple shops, each with its own catalog, pricing and serviceable areas.'],
            ['Is offline POS supported?', 'Yes — the Windows POS works offline and auto-syncs orders, inventory and payments when connectivity is restored.'],
        ];
        $i = 0;
        foreach ($faqs as [$q, $a]): $i++; ?>
            <div class="accordion-item">
                <h2 class="accordion-header"><button class="accordion-button <?= $i>1?'collapsed':'' ?>" data-bs-toggle="collapse" data-bs-target="#faq<?= $i ?>"><?= esc($q) ?></button></h2>
                <div id="faq<?= $i ?>" class="accordion-collapse collapse <?= $i===1?'show':'' ?>" data-bs-parent="#faqAcc"><div class="accordion-body text-secondary"><?= esc($a) ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="text-center mt-4">
        <p class="text-secondary small mb-1">Still need help?</p>
        <a href="#" class="btn btn-outline-primary"><i class="bi bi-headset me-1"></i>Contact support</a>
    </div>
</div></div>
<?= $this->endSection() ?>
