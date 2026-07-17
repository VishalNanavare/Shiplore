<?= $this->extend('layouts/vendor') ?>

<?= $this->section('content') ?>
<style>
.pi-grid-head,.pi-line{display:grid;grid-template-columns:70px 1.6fr 2.2fr 2.4fr 70px;gap:.5rem;align-items:start}
.pi-grid-head{font-weight:600;font-size:.8rem;color:#555;border-bottom:1px solid #e7eaf3;padding:.4rem .2rem}
.pi-line{padding:.5rem .2rem;border-bottom:1px solid #f2f4f9}
.pi-line input{height:30px;font-size:.82rem;padding:.1rem .35rem}
.pi-line .lbl{font-size:.68rem;color:#888;display:block}
.pi-results{position:absolute;z-index:40;max-height:320px;overflow:auto;background:#fff;border:1px solid #e7eaf3;border-radius:.4rem;box-shadow:0 8px 24px rgba(0,0,0,.08);min-width:420px}
.pi-results .item{padding:.45rem .6rem;cursor:pointer;border-bottom:1px solid #f2f4f9}
.pi-results .item:hover,.pi-results .item.active{background:#eef4ff}
.pi-mode{font-size:.7rem;font-weight:700;color:#0d6efd}
.pi-foot label{font-size:.7rem;color:#888;display:block}
.pi-modal-back{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1050;display:none;align-items:flex-start;justify-content:center}
.pi-modal{background:#fff;border-radius:.5rem;margin-top:6vh;width:680px;max-width:94vw;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.pi-modal h3{background:#2f6fed;color:#fff;margin:0;padding:.7rem 1rem;font-size:1.05rem;border-radius:.5rem .5rem 0 0}
</style>

<div id="pi"
     data-search="<?= site_url('vendor/purchase/search-products') ?>"
     data-store="<?= site_url('vendor/purchase/store') ?>"
     data-supsearch="<?= site_url('vendor/suppliers/search') ?>"
     data-supstore="<?= site_url('vendor/suppliers/store') ?>"
     data-exit="<?= site_url('vendor/inventory') ?>">
  <?= csrf_field() ?>
  <div class="d-flex justify-content-between align-items-center mb-2">
    <h1 class="h5 mb-0"><i class="bi bi-box-seam me-1"></i>Add Inventory</h1>
    <a href="<?= site_url('vendor/purchase/history') ?>" class="btn btn-sm btn-outline-secondary">Purchase History</a>
  </div>

  <div class="row g-2 mb-2">
    <div class="col-md-3 position-relative">
      <label class="form-label small mb-0">Supplier (Ctrl + U)</label>
      <input id="piSupplier" class="form-control form-control-sm" autocomplete="off" placeholder="Search supplier…">
      <input type="hidden" id="piSupplierId">
      <div id="piSupplierResults" class="pi-results" style="display:none"></div>
    </div>
    <div class="col-md-3"><label class="form-label small mb-0">Challan No</label><input id="piChallan" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small mb-0">Supplier Invoice ID</label><input id="piSupInv" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small mb-0">Invoice Date</label><input id="piDate" type="date" class="form-control form-control-sm" value="<?= esc(date('Y-m-d'), 'attr') ?>"></div>
  </div>
  <div class="row g-2 mb-2">
    <div class="col-md-6 position-relative">
      <label class="form-label small mb-0">Search Barcode / Name Wise Product (Ctrl + I) *</label>
      <input id="piSearch" class="form-control form-control-sm" autocomplete="off" placeholder="Scan or type to add a line…">
      <div id="piResults" class="pi-results" style="display:none"></div>
    </div>
    <div class="col-md-3"><label class="form-label small mb-0">Our Invoice No</label><div class="form-control form-control-sm bg-light" id="piOurNo"><?= esc($ourInvoiceNo) ?></div></div>
    <div class="col-md-3"><label class="form-label small mb-0">APMC</label><input id="piApmc" type="number" step="0.01" value="0" class="form-control form-control-sm"></div>
  </div>
  <div class="mb-2">
    <button id="piNewSupplier" class="btn btn-sm btn-primary">+ New Supplier ( Ctrl + X )</button>
    <button id="piAddProduct" class="btn btn-sm btn-warning text-dark">Add New Product ( Ctrl + M )</button>
  </div>

  <div id="piSupplierCard" class="border rounded p-2 mb-2 small" style="display:none"></div>

  <div class="border rounded">
    <div class="pi-grid-head"><div>Mode</div><div>Product</div><div>Pricing / Qty</div><div>Taxes / Total</div><div>Action</div></div>
    <div id="piLines"></div>
    <div id="piEmpty" class="text-center text-muted py-4 small">No lines yet — search a product (Ctrl + I) to add.</div>
  </div>

  <div class="pi-foot border rounded p-2 mt-2">
    <div class="row g-2">
      <div class="col"><label>Subtotal</label><div id="ftSub" class="fw-bold">0.000</div></div>
      <div class="col"><label>Discount</label><div id="ftDisc" class="fw-bold">0.000</div></div>
      <div class="col"><label>Total Qty</label><div id="ftQty" class="fw-bold">0</div></div>
      <div class="col"><label>SGST</label><div id="ftSgst" class="fw-bold">0.000</div></div>
      <div class="col"><label>CGST</label><div id="ftCgst" class="fw-bold">0.000</div></div>
      <div class="col"><label>IGST</label><div id="ftIgst" class="fw-bold">0.000</div></div>
      <div class="col"><label>CESS</label><div id="ftCess" class="fw-bold">0.000</div></div>
      <div class="col text-end"><label>Grand Total</label><div id="ftGrand" class="fw-bold fs-5">0</div></div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2 mt-2">
    <button id="piSave" class="btn btn-success">Save Inventory (Ctrl + S)</button>
    <button id="piExit" class="btn btn-danger">Exit (Ctrl + Q)</button>
  </div>

  <!-- New Supplier modal -->
  <div id="piSupModal" class="pi-modal-back">
    <div class="pi-modal">
      <h3>Add Supplier</h3>
      <div class="p-3">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small mb-0">Supplier Name *</label><input id="sName" class="form-control form-control-sm"></div>
          <div class="col-md-6"><label class="form-label small mb-0">GST No</label><input id="sGst" class="form-control form-control-sm"></div>
          <div class="col-md-6"><label class="form-label small mb-0">Aadhaar Card No</label><input id="sAadhaar" class="form-control form-control-sm"></div>
          <div class="col-md-6"><label class="form-label small mb-0">Phone No *</label><input id="sPhone" class="form-control form-control-sm"></div>
          <div class="col-md-12"><label class="form-label small mb-0">Email</label><input id="sEmail" class="form-control form-control-sm"></div>
          <div class="col-md-12"><label class="form-label small mb-0">Address *</label><textarea id="sAddress" rows="2" class="form-control form-control-sm"></textarea></div>
          <div class="col-md-5"><label class="form-label small mb-0">City *</label><input id="sCity" class="form-control form-control-sm"></div>
          <div class="col-md-4"><label class="form-label small mb-0">State *</label><input id="sState" class="form-control form-control-sm" maxlength="2" placeholder="27"></div>
          <div class="col-md-3"><label class="form-label small mb-0">Pincode *</label><input id="sPin" class="form-control form-control-sm"></div>
          <div class="col-md-6"><label class="form-label small mb-0">Sales Man</label><input id="sSalesman" class="form-control form-control-sm"></div>
          <div class="col-md-6"><label class="form-label small mb-0">Sales Man Phone No *</label><input id="sSalesPhone" class="form-control form-control-sm"></div>
        </div>
        <div class="text-center mt-3">
          <button id="sSave" class="btn btn-primary btn-sm">Save (Ctrl+S)</button>
          <button id="sCancel" class="btn btn-danger btn-sm">Cancel (Ctrl+X)</button>
        </div>
      </div>
    </div>
  </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset('js/purchase-intake.js') ?>"></script>
<?= $this->endSection() ?>
