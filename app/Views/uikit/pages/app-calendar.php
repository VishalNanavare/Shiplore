<?= $this->extend('uikit/_layout') ?>

<?= $this->section('content') ?>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="card uk-card mb-3"><div class="card-body">
            <button class="btn btn-primary w-100 mb-3"><i class="bi bi-plus-lg me-1"></i>Add Event</button>
            <h2 class="uk-section-title mb-2">Filters</h2>
            <?php foreach (['Work'=>'primary','Personal'=>'success','Billing'=>'warning','Holidays'=>'danger'] as $l=>$c): ?>
                <div class="form-check"><input class="form-check-input" type="checkbox" checked><label class="form-check-label small"><i class="bi bi-circle-fill text-<?= $c ?> me-1" style="font-size:.6rem"></i><?= $l ?></label></div>
            <?php endforeach; ?>
        </div></div>
        <div class="card uk-card"><div class="card-body">
            <h2 class="uk-section-title mb-2">Upcoming</h2>
            <?php foreach ([['Team standup','Jun 10 · 10:00','primary'],['Vendor review','Jun 12 · 14:00','success'],['Invoice due','Jun 15','warning']] as $e): ?>
                <div class="d-flex gap-2 mb-2"><span class="badge bg-<?= $e[2] ?>" style="width:4px">&nbsp;</span><div><div class="small fw-medium"><?= $e[0] ?></div><div class="text-secondary small"><?= $e[1] ?></div></div></div>
            <?php endforeach; ?>
        </div></div>
    </div>
    <div class="col-lg-9">
        <div class="card uk-card"><div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <div class="btn-group"><button class="btn btn-sm btn-light"><i class="bi bi-chevron-left"></i></button><button class="btn btn-sm btn-light"><i class="bi bi-chevron-right"></i></button></div>
                <h2 class="h5 mb-0">June 2026</h2>
                <div class="btn-group btn-group-sm"><button class="btn btn-primary">Month</button><button class="btn btn-outline-secondary">Week</button><button class="btn btn-outline-secondary">Day</button></div>
            </div>
            <div class="table-responsive"><table class="table table-bordered text-center mb-0" style="table-layout:fixed">
                <thead class="table-light"><tr><?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $d): ?><th class="small"><?= $d ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php
                $start = 1;        // June 1, 2026 is a Monday (Sun=0)
                $days = 30;
                $events = [10=>['Standup','primary'], 12=>['Review','success'], 15=>['Invoice','warning'], 21=>['Holiday','danger'], 25=>['Demo','primary']];
                $cell = 0; $day = 1;
                for ($row = 0; $row < 6 && $day <= $days; $row++) {
                    echo '<tr style="height:84px">';
                    for ($col = 0; $col < 7; $col++) {
                        if ($cell < $start || $day > $days) {
                            echo '<td class="text-secondary bg-light"></td>';
                        } else {
                            $isToday = ($day === 10);
                            echo '<td class="align-top text-start p-1">';
                            echo '<div class="small ' . ($isToday ? 'fw-bold text-primary' : '') . '">' . $day . '</div>';
                            if (isset($events[$day])) {
                                echo '<span class="badge bg-' . $events[$day][1] . ' d-block text-truncate mt-1" style="font-size:.62rem">' . $events[$day][0] . '</span>';
                            }
                            echo '</td>';
                            $day++;
                        }
                        $cell++;
                    }
                    echo '</tr>';
                }
                ?>
                </tbody>
            </table></div>
        </div></div>
    </div>
</div>
<?= $this->endSection() ?>
