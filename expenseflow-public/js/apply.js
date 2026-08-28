/**
 * ExpenseFlow Career Portal - Job Detail & Application Controller (detail.html)
 */
document.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  const jobId = urlParams.get('id');

  if (!jobId) {
    window.location.href = 'index.html';
    return;
  }

  const state = {
    jobId: parseInt(jobId, 10),
    job: null,
    currentStep: 1,
    submitting: false,
    selectedFile: null,
    postalLookupTimer: null,
  };

  const elements = {
    detailLoading: document.getElementById('detail-loading'),
    detailContent: document.getElementById('detail-content'),
    detailError: document.getElementById('detail-error'),
    errorMessage: document.getElementById('error-message'),

    // Job detail elements
    companyInitial: document.getElementById('job-company-initial'),
    companyName: document.getElementById('job-company-name'),
    jobTitle: document.getElementById('job-title'),
    breadcrumbTitle: document.getElementById('breadcrumb-title'),
    jobDepartment: document.getElementById('job-department'),
    jobTypeBadge: document.getElementById('job-type-badge'),
    jobLocation: document.getElementById('job-location'),
    jobSalary: document.getElementById('job-salary'),
    jobSalaryRow: document.getElementById('job-salary-row'),
    jobQuota: document.getElementById('job-quota'),
    jobQuotaRow: document.getElementById('job-quota-row'),
    jobDeadline: document.getElementById('job-deadline'),
    jobDescription: document.getElementById('job-description'),

    jobRequirements: document.getElementById('job-requirements'),
    jobRequirementsCard: document.getElementById('job-requirements-card'),
    requirementsCountBadge: document.getElementById('requirements-count-badge'),

    // Form elements
    applyForm: document.getElementById('apply-form'),
    formSuccess: document.getElementById('form-success'),
    applicantSuccessName: document.getElementById('applicant-success-name'),
    jobSuccessTitle: document.getElementById('job-success-title'),
    btnPrevStep: document.getElementById('btn-prev-step'),
    btnNextStep: document.getElementById('btn-next-step'),
    btnSubmit: document.getElementById('btn-submit'),
    steps: [
      document.getElementById('step-1'),
      document.getElementById('step-2'),
    ],
    stepIndicators: [
      document.getElementById('indicator-1'),
      document.getElementById('indicator-2'),
    ],

    // File upload elements
    fileDropzone: document.getElementById('file-dropzone'),
    fileInput: document.getElementById('resume-file'),
    filePreview: document.getElementById('file-preview'),
    fileName: document.getElementById('file-name'),
    fileSize: document.getElementById('file-size'),
    fileRemoveBtn: document.getElementById('file-remove-btn'),

    // Summary Card elements (Step 2)
    summaryName: document.getElementById('summary-name'),
    summaryEmail: document.getElementById('summary-email'),
    summaryEducation: document.getElementById('summary-education'),

    // Postal code lookup status
    postalCodeInput: document.getElementById('postal_code'),
    postalSpinner: document.getElementById('postal-spinner'),
    postalHint: document.getElementById('postal-hint'),
    postalHintText: document.getElementById('postal-hint-text'),
  };

  // ── Database Kode Pos Indonesia (Offline Fast Dictionary) ───────────────────
  const POSTAL_DICT = {
    // DKI Jakarta
    '10110': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Gambir', subdistrict: 'Gambir' },
    '10120': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Gambir', subdistrict: 'Kebon Kelapa' },
    '10210': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Tanah Abang', subdistrict: 'Bendungan Hilir' },
    '10220': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Tanah Abang', subdistrict: 'Karet Tengsin' },
    '10270': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Tanah Abang', subdistrict: 'Gelora' },
    '10310': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Menteng', subdistrict: 'Menteng' },
    '10320': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Menteng', subdistrict: 'Pegangsaan' },
    '10410': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Senen', subdistrict: 'Senen' },
    '10510': { province: 'DKI Jakarta', city: 'Jakarta Pusat', district: 'Cempaka Putih', subdistrict: 'Cempaka Putih Timur' },
    '11110': { province: 'DKI Jakarta', city: 'Jakarta Barat', district: 'Taman Sari', subdistrict: 'Pinangsia' },
    '11210': { province: 'DKI Jakarta', city: 'Jakarta Barat', district: 'Tambora', subdistrict: 'Tambora' },
    '11410': { province: 'DKI Jakarta', city: 'Jakarta Barat', district: 'Palmerah', subdistrict: 'Slipi' },
    '11470': { province: 'DKI Jakarta', city: 'Jakarta Barat', district: 'Grogol Petamburan', subdistrict: 'Tomang' },
    '11510': { province: 'DKI Jakarta', city: 'Jakarta Barat', district: 'Kebon Jeruk', subdistrict: 'Duri Kepa' },
    '11530': { province: 'DKI Jakarta', city: 'Jakarta Barat', district: 'Kebon Jeruk', subdistrict: 'Sukabumi Utara' },
    '11710': { province: 'DKI Jakarta', city: 'Jakarta Barat', district: 'Cengkareng', subdistrict: 'Cengkareng Barat' },
    '11810': { province: 'DKI Jakarta', city: 'Jakarta Barat', district: 'Kalideres', subdistrict: 'Kalideres' },
    '12110': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Kebayoran Baru', subdistrict: 'Selong' },
    '12120': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Kebayoran Baru', subdistrict: 'Gunung' },
    '12130': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Kebayoran Baru', subdistrict: 'Kramat Pela' },
    '12190': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Kebayoran Baru', subdistrict: 'Senayan' },
    '12210': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Kebayoran Lama', subdistrict: 'Grogol Utara' },
    '12240': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Kebayoran Lama', subdistrict: 'Kebayoran Lama Selatan' },
    '12310': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Pesanggrahan', subdistrict: 'Bintaro' },
    '12410': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Cilandak', subdistrict: 'Cipete Selatan' },
    '12430': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Cilandak', subdistrict: 'Cilandak Barat' },
    '12440': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Cilandak', subdistrict: 'Lebak Bulus' },
    '12510': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Pasar Minggu', subdistrict: 'Pasar Minggu' },
    '12550': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Pasar Minggu', subdistrict: 'Ragunan' },
    '12610': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Jagakarsa', subdistrict: 'Tanjung Barat' },
    '12620': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Jagakarsa', subdistrict: 'Jagakarsa' },
    '12710': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Mampang Prapatan', subdistrict: 'Kuningan Barat' },
    '12810': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Tebet', subdistrict: 'Tebet Barat' },
    '12870': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Tebet', subdistrict: 'Menteng Dalam' },
    '12910': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Setiabudi', subdistrict: 'Karet' },
    '12920': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Setiabudi', subdistrict: 'Karet Semanggi' },
    '12950': { province: 'DKI Jakarta', city: 'Jakarta Selatan', district: 'Setiabudi', subdistrict: 'Kuningan Timur' },
    '13110': { province: 'DKI Jakarta', city: 'Jakarta Timur', district: 'Matraman', subdistrict: 'Pisangan Baru' },
    '13210': { province: 'DKI Jakarta', city: 'Jakarta Timur', district: 'Pulo Gadung', subdistrict: 'Kayu Putih' },
    '13310': { province: 'DKI Jakarta', city: 'Jakarta Timur', district: 'Jatinegara', subdistrict: 'Bali Mester' },
    '13410': { province: 'DKI Jakarta', city: 'Jakarta Timur', district: 'Duren Sawit', subdistrict: 'Pondok Bambu' },
    '13510': { province: 'DKI Jakarta', city: 'Jakarta Timur', district: 'Kramat Jati', subdistrict: 'Kramat Jati' },
    '13710': { province: 'DKI Jakarta', city: 'Jakarta Timur', district: 'Ciracas', subdistrict: 'Ciracas' },
    '13810': { province: 'DKI Jakarta', city: 'Jakarta Timur', district: 'Cipayung', subdistrict: 'Lubang Buaya' },
    '13910': { province: 'DKI Jakarta', city: 'Jakarta Timur', district: 'Cakung', subdistrict: 'Cakung Barat' },
    '14110': { province: 'DKI Jakarta', city: 'Jakarta Utara', district: 'Cilincing', subdistrict: 'Kali Baru' },
    '14210': { province: 'DKI Jakarta', city: 'Jakarta Utara', district: 'Kelapa Gading', subdistrict: 'Kelapa Gading Barat' },
    '14310': { province: 'DKI Jakarta', city: 'Jakarta Utara', district: 'Tanjung Priok', subdistrict: 'Tanjung Priok' },
    '14410': { province: 'DKI Jakarta', city: 'Jakarta Utara', district: 'Penjaringan', subdistrict: 'Penjaringan' },
    '14450': { province: 'DKI Jakarta', city: 'Jakarta Utara', district: 'Penjaringan', subdistrict: 'Pluit' },
    '14460': { province: 'DKI Jakarta', city: 'Jakarta Utara', district: 'Pademangan', subdistrict: 'Ancol' },

    // Banten
    '15111': { province: 'Banten', city: 'Kota Tangerang', district: 'Tangerang', subdistrict: 'Sukarasa' },
    '15117': { province: 'Banten', city: 'Kota Tangerang', district: 'Tangerang', subdistrict: 'Babakan' },
    '15138': { province: 'Banten', city: 'Kota Tangerang', district: 'Cipondoh', subdistrict: 'Poris Plawad' },
    '15143': { province: 'Banten', city: 'Kota Tangerang', district: 'Pinang', subdistrict: 'Kunciran' },
    '15151': { province: 'Banten', city: 'Kota Tangerang', district: 'Ciledug', subdistrict: 'Sudimara Barat' },
    '15220': { province: 'Banten', city: 'Kota Tangerang Selatan', district: 'Pondok Aren', subdistrict: 'Pondok Jaya' },
    '15224': { province: 'Banten', city: 'Kota Tangerang Selatan', district: 'Pondok Aren', subdistrict: 'Pondok Kacang Barat' },
    '15310': { province: 'Banten', city: 'Kota Tangerang Selatan', district: 'Serpong', subdistrict: 'Lengkong Gudang' },
    '15321': { province: 'Banten', city: 'Kota Tangerang Selatan', district: 'Serpong Utara', subdistrict: 'Pakulonan (BSD)' },
    '15411': { province: 'Banten', city: 'Kota Tangerang Selatan', district: 'Ciputat', subdistrict: 'Ciputat' },
    '15419': { province: 'Banten', city: 'Kota Tangerang Selatan', district: 'Pamulang', subdistrict: 'Pamulang Barat' },
    '15810': { province: 'Banten', city: 'Kabupaten Tangerang', district: 'Kelapa Dua', subdistrict: 'Bencongan (Karawaci)' },

    // Jawa Barat
    '16110': { province: 'Jawa Barat', city: 'Kota Bogor', district: 'Bogor Tengah', subdistrict: 'Pabaton' },
    '16128': { province: 'Jawa Barat', city: 'Kota Bogor', district: 'Bogor Timur', subdistrict: 'Baranangsiang' },
    '16151': { province: 'Jawa Barat', city: 'Kota Bogor', district: 'Bogor Utara', subdistrict: 'Bantarjati' },
    '16320': { province: 'Jawa Barat', city: 'Kabupaten Bogor', district: 'Cibinong', subdistrict: 'Cirimekar' },
    '16411': { province: 'Jawa Barat', city: 'Kota Depok', district: 'Pancoran Mas', subdistrict: 'Depok' },
    '16424': { province: 'Jawa Barat', city: 'Kota Depok', district: 'Beji', subdistrict: 'Pondok Cina' },
    '16431': { province: 'Jawa Barat', city: 'Kota Depok', district: 'Sukmajaya', subdistrict: 'Sukmajaya' },
    '16511': { province: 'Jawa Barat', city: 'Kota Depok', district: 'Sawangan', subdistrict: 'Sawangan' },
    '16911': { province: 'Jawa Barat', city: 'Kota Depok', district: 'Cimanggis', subdistrict: 'Harjamukti' },
    '17111': { province: 'Jawa Barat', city: 'Kota Bekasi', district: 'Bekasi Timur', subdistrict: 'Aren Jaya' },
    '17131': { province: 'Jawa Barat', city: 'Kota Bekasi', district: 'Bekasi Barat', subdistrict: 'Bintara' },
    '17144': { province: 'Jawa Barat', city: 'Kota Bekasi', district: 'Bekasi Selatan', subdistrict: 'Pekayon Jaya' },
    '17148': { province: 'Jawa Barat', city: 'Kota Bekasi', district: 'Bekasi Selatan', subdistrict: 'Kayuringin Jaya' },
    '17510': { province: 'Jawa Barat', city: 'Kabupaten Bekasi', district: 'Cikarang Barat', subdistrict: 'Telaga Asih' },
    '17530': { province: 'Jawa Barat', city: 'Kabupaten Bekasi', district: 'Cikarang Pusat', subdistrict: 'Sukamahi' },
    '40111': { province: 'Jawa Barat', city: 'Kota Bandung', district: 'Sumur Bandung', subdistrict: 'Braga' },
    '40115': { province: 'Jawa Barat', city: 'Kota Bandung', district: 'Coblong', subdistrict: 'Dago' },
    '40132': { province: 'Jawa Barat', city: 'Kota Bandung', district: 'Sukasari', subdistrict: 'Gegerkalong' },
    '40141': { province: 'Jawa Barat', city: 'Kota Bandung', district: 'Cidadap', subdistrict: 'Hegarmanah' },
    '40262': { province: 'Jawa Barat', city: 'Kota Bandung', district: 'Lengkong', subdistrict: 'Burangrang' },
    '40286': { province: 'Jawa Barat', city: 'Kota Bandung', district: 'Buahbatu', subdistrict: 'Margasari' },
    '40511': { province: 'Jawa Barat', city: 'Kota Cimahi', district: 'Cimahi Tengah', subdistrict: 'Cimahi' },

    // Jawa Tengah & DI Yogyakarta
    '50131': { province: 'Jawa Tengah', city: 'Kota Semarang', district: 'Semarang Tengah', subdistrict: 'Pekunden' },
    '50241': { province: 'Jawa Tengah', city: 'Kota Semarang', district: 'Gajahmungkur', subdistrict: 'Gajahmungkur' },
    '50275': { province: 'Jawa Tengah', city: 'Kota Semarang', district: 'Tembalang', subdistrict: 'Tembalang' },
    '57111': { province: 'Jawa Tengah', city: 'Kota Surakarta', district: 'Banjarsari', subdistrict: 'Keprabon' },
    '57121': { province: 'Jawa Tengah', city: 'Kota Surakarta', district: 'Jebres', subdistrict: 'Jebres' },
    '57141': { province: 'Jawa Tengah', city: 'Kota Surakarta', district: 'Laweyan', subdistrict: 'Laweyan' },
    '55111': { province: 'D.I. Yogyakarta', city: 'Kota Yogyakarta', district: 'Gondomanan', subdistrict: 'Prawirodirjan' },
    '55222': { province: 'D.I. Yogyakarta', city: 'Kota Yogyakarta', district: 'Danurejan', subdistrict: 'Bausasran' },
    '55281': { province: 'D.I. Yogyakarta', city: 'Kabupaten Sleman', district: 'Depok', subdistrict: 'Caturtunggal' },
    '55284': { province: 'D.I. Yogyakarta', city: 'Kabupaten Sleman', district: 'Ngaglik', subdistrict: 'Sinduharjo' },
    '55181': { province: 'D.I. Yogyakarta', city: 'Kabupaten Bantul', district: 'Kasihan', subdistrict: 'Tirtonirmolo' },

    // Jawa Timur
    '60111': { province: 'Jawa Timur', city: 'Kota Surabaya', district: 'Gubeng', subdistrict: 'Gubeng' },
    '60151': { province: 'Jawa Timur', city: 'Kota Surabaya', district: 'Tegalsari', subdistrict: 'Tegalsari' },
    '60241': { province: 'Jawa Timur', city: 'Kota Surabaya', district: 'Wonokromo', subdistrict: 'Wonokromo' },
    '60271': { province: 'Jawa Timur', city: 'Kota Surabaya', district: 'Genteng', subdistrict: 'Genteng' },
    '60293': { province: 'Jawa Timur', city: 'Kota Surabaya', district: 'Rungkut', subdistrict: 'Rungkut Menanggal' },
    '61211': { province: 'Jawa Timur', city: 'Kabupaten Sidoarjo', district: 'Sidoarjo', subdistrict: 'Sidokumpul' },
    '65111': { province: 'Jawa Timur', city: 'Kota Malang', district: 'Klojen', subdistrict: 'Klojen' },
    '65141': { province: 'Jawa Timur', city: 'Kota Malang', district: 'Lowokwaru', subdistrict: 'Lowokwaru' },

    // Bali & Nusa Tenggara
    '80111': { province: 'Bali', city: 'Kota Denpasar', district: 'Denpasar Barat', subdistrict: 'Dauh Puri' },
    '80232': { province: 'Bali', city: 'Kota Denpasar', district: 'Denpasar Selatan', subdistrict: 'Sanur' },
    '80361': { province: 'Bali', city: 'Kabupaten Badung', district: 'Kuta', subdistrict: 'Kuta' },
    '83111': { province: 'Nusa Tenggara Barat', city: 'Kota Mataram', district: 'Mataram', subdistrict: 'Mataram Barat' },

    // Sumatera
    '20111': { province: 'Sumatera Utara', city: 'Kota Medan', district: 'Medan Kota', subdistrict: 'Mesjid' },
    '20151': { province: 'Sumatera Utara', city: 'Kota Medan', district: 'Medan Baru', subdistrict: 'Padang Bulan' },
    '20211': { province: 'Sumatera Utara', city: 'Kota Medan', district: 'Medan Timur', subdistrict: 'Perintis' },
    '25111': { province: 'Sumatera Barat', city: 'Kota Padang', district: 'Padang Barat', subdistrict: 'Kampung Jao' },
    '28111': { province: 'Riau', city: 'Kota Pekanbaru', district: 'Sukajadi', subdistrict: 'Kampung Melayu' },
    '30111': { province: 'Sumatera Selatan', city: 'Kota Palembang', district: 'Ilir Timur I', subdistrict: '16 Ilir' },
    '35111': { province: 'Lampung', city: 'Kota Bandar Lampung', district: 'Tanjung Karang Pusat', subdistrict: 'Kelapa Tiga' },

    // Kalimantan & Sulawesi
    '70111': { province: 'Kalimantan Selatan', city: 'Kota Banjarmasin', district: 'Banjarmasin Tengah', subdistrict: 'Kertak Baru Ilir' },
    '75111': { province: 'Kalimantan Timur', city: 'Kota Samarinda', district: 'Samarinda Kota', subdistrict: 'Pasar Pagi' },
    '76111': { province: 'Kalimantan Timur', city: 'Kota Balikpapan', district: 'Balikpapan Kota', subdistrict: 'Klandasan Ulu' },
    '78111': { province: 'Kalimantan Barat', city: 'Kota Pontianak', district: 'Pontianak Kota', subdistrict: 'Tengah' },
    '90111': { province: 'Sulawesi Selatan', city: 'Kota Makassar', district: 'Ujung Pandang', subdistrict: 'Baru' },
    '90222': { province: 'Sulawesi Selatan', city: 'Kota Makassar', district: 'Panakkukang', subdistrict: 'Masale' },
    '95111': { province: 'Sulawesi Utara', city: 'Kota Manado', district: 'Wenang', subdistrict: 'Wenang Selatan' },
  };

  // Prefix fallback if specific 5-digit is not mapped
  const PREFIX_DICT = {
    '10': { province: 'DKI Jakarta', city: 'Jakarta Pusat' },
    '11': { province: 'DKI Jakarta', city: 'Jakarta Barat' },
    '12': { province: 'DKI Jakarta', city: 'Jakarta Selatan' },
    '13': { province: 'DKI Jakarta', city: 'Jakarta Timur' },
    '14': { province: 'DKI Jakarta', city: 'Jakarta Utara' },
    '15': { province: 'Banten', city: 'Tangerang / Tangerang Selatan' },
    '16': { province: 'Jawa Barat', city: 'Bogor / Depok' },
    '17': { province: 'Jawa Barat', city: 'Bekasi' },
    '40': { province: 'Jawa Barat', city: 'Kota Bandung' },
    '41': { province: 'Jawa Barat', city: 'Purwakarta / Subang' },
    '42': { province: 'Banten', city: 'Serang / Cilegon' },
    '43': { province: 'Jawa Barat', city: 'Sukabumi / Cianjur' },
    '44': { province: 'Jawa Barat', city: 'Garut' },
    '45': { province: 'Jawa Barat', city: 'Cirebon / Kuningan' },
    '46': { province: 'Jawa Barat', city: 'Tasikmalaya / Ciamis' },
    '50': { province: 'Jawa Tengah', city: 'Kota Semarang' },
    '51': { province: 'Jawa Tengah', city: 'Pekalongan / Batang' },
    '52': { province: 'Jawa Tengah', city: 'Tegal / Brebes' },
    '53': { province: 'Jawa Tengah', city: 'Banyumas / Purwokerto' },
    '55': { province: 'D.I. Yogyakarta', city: 'D.I. Yogyakarta' },
    '56': { province: 'Jawa Tengah', city: 'Magelang' },
    '57': { province: 'Jawa Tengah', city: 'Surakarta / Solo' },
    '60': { province: 'Jawa Timur', city: 'Kota Surabaya' },
    '61': { province: 'Jawa Timur', city: 'Sidoarjo / Gresik' },
    '62': { province: 'Jawa Timur', city: 'Mojokerto / Jombang' },
    '63': { province: 'Jawa Timur', city: 'Madiun / Ngawi' },
    '64': { province: 'Jawa Timur', city: 'Kediri' },
    '65': { province: 'Jawa Timur', city: 'Malang / Batu' },
    '66': { province: 'Jawa Timur', city: 'Tulungagung / Blitar' },
    '67': { province: 'Jawa Timur', city: 'Probolinggo / Pasuruan' },
    '68': { province: 'Jawa Timur', city: 'Jember / Banyuwangi' },
    '80': { province: 'Bali', city: 'Denpasar / Badung' },
    '82': { province: 'Bali', city: 'Tabanan' },
    '83': { province: 'Nusa Tenggara Barat', city: 'Mataram / Lombok' },
    '85': { province: 'Nusa Tenggara Timur', city: 'Kupang' },
    '20': { province: 'Sumatera Utara', city: 'Kota Medan' },
    '23': { province: 'Aceh', city: 'Banda Aceh' },
    '25': { province: 'Sumatera Barat', city: 'Kota Padang' },
    '28': { province: 'Riau', city: 'Kota Pekanbaru' },
    '29': { province: 'Kepulauan Riau', city: 'Batam / Tanjung Pinang' },
    '30': { province: 'Sumatera Selatan', city: 'Kota Palembang' },
    '34': { province: 'Bangka Belitung', city: 'Pangkal Pinang' },
    '35': { province: 'Lampung', city: 'Bandar Lampung' },
    '70': { province: 'Kalimantan Selatan', city: 'Banjarmasin' },
    '75': { province: 'Kalimantan Timur', city: 'Samarinda' },
    '76': { province: 'Kalimantan Timur', city: 'Balikpapan' },
    '77': { province: 'Kalimantan Utara', city: 'Tarakan' },
    '78': { province: 'Kalimantan Barat', city: 'Pontianak' },
    '90': { province: 'Sulawesi Selatan', city: 'Kota Makassar' },
    '94': { province: 'Sulawesi Tengah', city: 'Palu' },
    '95': { province: 'Sulawesi Utara', city: 'Manado' },
    '97': { province: 'Maluku', city: 'Ambon' },
    '99': { province: 'Papua', city: 'Jayapura' },
  };

  // In-memory cache for full postal code dataset if loaded
  let fullPostalDataset = null;

  // ── Smart Postal Code Auto-detection Function ───────────────────────────────
  async function handlePostalCodeLookup(postalCode) {
    const cleanCode = (postalCode || '').trim().replace(/\D/g, '');
    if (!cleanCode || cleanCode.length < 3) {
      if (elements.postalHint) elements.postalHint.classList.add('hidden');
      return;
    }

    if (elements.postalSpinner) elements.postalSpinner.classList.remove('hidden');

    const provinceInput = document.getElementById('province');
    const cityInput = document.getElementById('city');
    const districtInput = document.getElementById('district');
    const subdistrictInput = document.getElementById('subdistrict');

    let matched = null;

    // 1. Query via Backend API Endpoint (/public/postal-code/{code})
    try {
      if (window.CareerAPI && typeof window.CareerAPI.lookupPostalCode === 'function') {
        const apiResults = await window.CareerAPI.lookupPostalCode(cleanCode);
        if (apiResults && apiResults.length > 0) {
          matched = apiResults[0];
        }
      }
    } catch (err) {
      // Continue to fallback
    }

    // 2. Query via local data/postal_codes.json (if offline or static file)
    if (!matched && cleanCode.length === 5) {
      try {
        if (!fullPostalDataset) {
          const res = await fetch('data/postal_codes.json');
          if (res.ok) {
            fullPostalDataset = await res.json();
          }
        }
        if (fullPostalDataset && fullPostalDataset[cleanCode] && fullPostalDataset[cleanCode].length > 0) {
          const first = fullPostalDataset[cleanCode][0];
          matched = {
            province: first.p,
            city: first.c,
            district: first.d,
            subdistrict: first.s,
          };
        }
      } catch (e) {
        // Fallback
      }
    }

    // 3. Fallback to offline fast dictionary
    if (!matched && POSTAL_DICT[cleanCode]) {
      matched = POSTAL_DICT[cleanCode];
    }

    // 4. Fallback to 2-digit prefix dictionary
    if (!matched) {
      const prefix2 = cleanCode.substring(0, 2);
      if (PREFIX_DICT[prefix2]) {
        matched = {
          province: PREFIX_DICT[prefix2].province,
          city: PREFIX_DICT[prefix2].city,
          district: '',
          subdistrict: '',
        };
      }
    }

    if (elements.postalSpinner) elements.postalSpinner.classList.add('hidden');

    // If matched, auto-populate inputs and show friendly visual highlight
    if (matched) {
      if (provinceInput && matched.province) {
        provinceInput.value = matched.province;
        highlightField(provinceInput);
      }
      if (cityInput && matched.city) {
        cityInput.value = matched.city;
        highlightField(cityInput);
      }
      if (districtInput && matched.district) {
        districtInput.value = matched.district;
        highlightField(districtInput);
      }
      if (subdistrictInput && matched.subdistrict) {
        subdistrictInput.value = matched.subdistrict;
        highlightField(subdistrictInput);
      }

      if (elements.postalHint && elements.postalHintText) {
        const parts = [
          matched.subdistrict ? `Kel. ${matched.subdistrict}` : '',
          matched.district ? `Kec. ${matched.district}` : '',
          matched.city,
          matched.province
        ].filter(Boolean);
        elements.postalHintText.textContent = `Wilayah terdeteksi: ${parts.join(', ')}`;
        elements.postalHint.classList.remove('hidden');
      }
    } else {
      if (elements.postalHint) elements.postalHint.classList.add('hidden');
    }
  }

  function highlightField(inputEl) {
    if (!inputEl) return;
    inputEl.classList.add('border-emerald-500', 'bg-emerald-500/10');
    setTimeout(() => {
      inputEl.classList.remove('border-emerald-500', 'bg-emerald-500/10');
    }, 1500);
  }

  // ── Load Job Detail from API ───────────────────────────────────────────────
  async function loadJobDetail() {
    try {
      elements.detailLoading?.classList.remove('hidden');
      elements.detailContent?.classList.add('hidden');
      elements.detailError?.classList.add('hidden');

      const raw = await window.CareerAPI.getJobDetail(state.jobId);
      const job = raw?.data || raw;
      state.job = job;

      renderJobDetail(job);


      elements.detailLoading?.classList.add('hidden');
      elements.detailContent?.classList.remove('hidden');
      if (window.lucide) window.lucide.createIcons();
    } catch (err) {
      console.error(err);
      elements.detailLoading?.classList.add('hidden');
      elements.detailError?.classList.remove('hidden');
      if (elements.errorMessage) {
        elements.errorMessage.textContent = err.message || 'Gagal memuat rincian lowongan kerja.';
      }
      if (window.lucide) window.lucide.createIcons();
    }
  }

  function renderJobDetail(job) {
    const companyInitial = (job.company?.name || window.CONFIG.COMPANY_NAME || 'E').charAt(0).toUpperCase();
    const companyName = job.company?.name || window.CONFIG.COMPANY_NAME || 'ExpenseFlow';

    document.title = `${job.title} — ${companyName} Karir`;

    if (elements.companyInitial) elements.companyInitial.textContent = companyInitial;
    if (elements.companyName) elements.companyName.textContent = companyName;
    if (elements.jobTitle) elements.jobTitle.textContent = job.title;
    if (elements.breadcrumbTitle) elements.breadcrumbTitle.textContent = job.title;
    if (elements.jobDepartment) elements.jobDepartment.textContent = job.department || 'Umum';

    if (elements.jobTypeBadge) {
      elements.jobTypeBadge.className = `text-xs font-semibold px-2.5 py-0.5 rounded-full border ${window.CareerUtils.getEmploymentBadgeClass(job.employment_type)}`;
      elements.jobTypeBadge.textContent = window.CareerUtils.getEmploymentTypeLabel(job.employment_type);
    }

    if (elements.jobLocation) {
      elements.jobLocation.textContent = job.location || 'Indonesia';
    }

    if (elements.jobDeadline) {
      elements.jobDeadline.textContent = job.deadline ? window.CareerUtils.formatShortDate(job.deadline) : 'Terbuka';
    }

    if (elements.jobSalaryRow) {
      if (job.show_salary && (job.salary_min || job.salary_max)) {
        elements.jobSalaryRow.classList.remove('hidden');
        if (job.salary_min && job.salary_max) {
          elements.jobSalary.textContent = `${window.CareerUtils.formatRupiah(job.salary_min)} - ${window.CareerUtils.formatRupiah(job.salary_max)}`;
        } else if (job.salary_min) {
          elements.jobSalary.textContent = `Mulai ${window.CareerUtils.formatRupiah(job.salary_min)}`;
        }
      } else {
        elements.jobSalaryRow.classList.add('hidden');
      }
    }

    if (elements.jobQuotaRow) {
      if (job.max_applicants) {
        elements.jobQuotaRow.classList.remove('hidden');
        elements.jobQuota.textContent = `Maksimal ${job.max_applicants} Pelamar (Pendaftaran otomatis ditutup jika kuota terpenuhi)`;
      } else {
        elements.jobQuotaRow.classList.add('hidden');
      }
    }

    // Render Deskripsi
    if (elements.jobDescription) {
      const descLines = (job.description || '').split('\n').filter(l => l.trim().length > 0);
      elements.jobDescription.innerHTML = descLines.map(line => {
        const isBullet = /^[\s•\-\*]/.test(line);
        if (isBullet) {
          const clean = line.replace(/^[\s•\-\*\d\.\)\-]+/, '').trim();
          return `
            <div class="flex items-start gap-2 my-1.5 text-slate-300 text-xs sm:text-sm">
              <span class="w-1.5 h-1.5 rounded-full bg-brand-400 mt-2 shrink-0"></span>
              <span class="leading-relaxed">${window.CareerUtils.escapeHtml(clean)}</span>
            </div>
          `;
        }
        return `<p class="mb-2 text-slate-300 text-xs sm:text-sm leading-relaxed">${window.CareerUtils.escapeHtml(line)}</p>`;
      }).join('');
    }

    // Render Kualifikasi
    if (elements.jobRequirementsCard) {
      if (job.requirements && job.requirements.trim()) {
        elements.jobRequirementsCard.classList.remove('hidden');
        if (elements.jobRequirements) {
          const lines = job.requirements.split('\n')
            .map(l => l.replace(/^[\s•\-\*\d\.\)\-]+/, '').trim())
            .filter(l => l.length > 0);

          if (elements.requirementsCountBadge) {
            elements.requirementsCountBadge.textContent = `${lines.length} Kriteria`;
          }

          elements.jobRequirements.innerHTML = lines.map(line => `
            <li class="flex items-start gap-2 text-slate-300 text-xs sm:text-sm">
              <i data-lucide="check" class="w-4 h-4 text-emerald-400 mt-0.5 shrink-0"></i>
              <span class="leading-relaxed">${window.CareerUtils.escapeHtml(line)}</span>
            </li>
          `).join('');
        }
      } else {
        elements.jobRequirementsCard.classList.add('hidden');
      }
    }
  }

  // ── 2-Step Form Navigation Logic ────────────────────────────────────────────
  function setStep(stepNumber) {
    state.currentStep = stepNumber;

    elements.steps.forEach((stepEl, idx) => {
      if (idx + 1 === stepNumber) {
        stepEl?.classList.remove('hidden');
      } else {
        stepEl?.classList.add('hidden');
      }
    });

    elements.stepIndicators.forEach((indEl, idx) => {
      const stepIdx = idx + 1;
      const dot = indEl?.querySelector('.step-dot');
      const label = indEl?.querySelector('.step-label');

      if (stepIdx === stepNumber) {
        dot?.classList.remove('bg-white/5', 'text-slate-400', 'border-white/10');
        dot?.classList.add('bg-brand-600', 'text-white', 'border-brand-500');
        label?.classList.add('text-brand-400', 'font-bold');
        label?.classList.remove('text-slate-500', 'font-medium');
      } else if (stepIdx < stepNumber) {
        dot?.classList.remove('bg-white/5', 'text-slate-400', 'border-white/10');
        dot?.classList.add('bg-brand-600', 'text-white', 'border-brand-500');
        label?.classList.remove('text-slate-500');
        label?.classList.add('text-slate-300');
      } else {
        dot?.classList.remove('bg-brand-600', 'text-white', 'border-brand-500');
        dot?.classList.add('bg-white/5', 'text-slate-400', 'border-white/10');
        label?.classList.remove('text-brand-400', 'font-bold', 'text-slate-300');
        label?.classList.add('text-slate-500', 'font-medium');
      }
    });

    // Toggle navigation buttons
    if (stepNumber === 1) {
      elements.btnPrevStep?.classList.add('hidden');
      elements.btnNextStep?.classList.remove('hidden');
      elements.btnSubmit?.classList.add('hidden');
    } else if (stepNumber === 2) {
      elements.btnPrevStep?.classList.remove('hidden');
      elements.btnNextStep?.classList.add('hidden');
      elements.btnSubmit?.classList.remove('hidden');
      updateSummary();
    }

    if (window.lucide) window.lucide.createIcons();
  }

  function validateStep1() {
    let isValid = true;
    const fullName = document.getElementById('full_name');
    const gender = document.getElementById('gender');
    const birthPlace = document.getElementById('birth_place');
    const birthDate = document.getElementById('birth_date');
    const nationality = document.getElementById('nationality');
    const email = document.getElementById('email');
    const phone = document.getElementById('phone');
    const education = document.getElementById('education');
    const institutionName = document.getElementById('institution_name');
    const expYears = document.getElementById('experience_years');
    const noticePeriod = document.getElementById('notice_period');
    const postalCode = document.getElementById('postal_code');
    const province = document.getElementById('province');
    const city = document.getElementById('city');
    const district = document.getElementById('district');
    const address = document.getElementById('address');

    clearError('full_name');
    clearError('gender');
    clearError('birth_place');
    clearError('birth_date');
    clearError('nationality');
    clearError('email');
    clearError('phone');
    clearError('education');
    clearError('institution_name');
    clearError('experience_years');
    clearError('notice_period');
    clearError('postal_code');
    clearError('province');
    clearError('city');
    clearError('district');
    clearError('address');

    if (!fullName || !fullName.value.trim()) {
      showFieldError('full_name', 'Nama lengkap wajib diisi.');
      isValid = false;
    }

    if (!gender || !gender.value.trim()) {
      showFieldError('gender', 'Jenis kelamin wajib dipilih.');
      isValid = false;
    }

    if (!birthPlace || !birthPlace.value.trim()) {
      showFieldError('birth_place', 'Tempat lahir wajib diisi.');
      isValid = false;
    }

    if (!birthDate || !birthDate.value.trim()) {
      showFieldError('birth_date', 'Tanggal lahir wajib diisi.');
      isValid = false;
    }

    if (!nationality || !nationality.value.trim()) {
      showFieldError('nationality', 'Kewarganegaraan wajib dipilih.');
      isValid = false;
    }

    if (!email || !email.value.trim()) {
      showFieldError('email', 'Alamat email wajib diisi.');
      isValid = false;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
      showFieldError('email', 'Format alamat email tidak valid.');
      isValid = false;
    }

    if (!phone || !phone.value.trim()) {
      showFieldError('phone', 'Nomor WhatsApp / HP wajib diisi.');
      isValid = false;
    } else if (phone.value.trim().length < 8) {
      showFieldError('phone', 'Nomor telepon minimal 8 digit.');
      isValid = false;
    }

    if (!education || !education.value.trim()) {
      showFieldError('education', 'Pendidikan terakhir wajib dipilih.');
      isValid = false;
    }

    if (!institutionName || !institutionName.value.trim()) {
      showFieldError('institution_name', 'Nama sekolah / universitas terakhir wajib diisi.');
      isValid = false;
    }

    if (!expYears || expYears.value.trim() === '') {
      showFieldError('experience_years', 'Pengalaman kerja wajib diisi (isi 0 jika Fresh Graduate).');
      isValid = false;
    } else if (isNaN(expYears.value) || parseInt(expYears.value, 10) < 0 || parseInt(expYears.value, 10) > 50) {
      showFieldError('experience_years', 'Pengalaman kerja harus angka antara 0 - 50 tahun.');
      isValid = false;
    }

    if (!noticePeriod || !noticePeriod.value.trim()) {
      showFieldError('notice_period', 'Ketersediaan mulai bekerja (Notice Period) wajib dipilih.');
      isValid = false;
    }

    if (!postalCode || !postalCode.value.trim()) {
      showFieldError('postal_code', 'Kode pos domisili wajib diisi.');
      isValid = false;
    }

    if (!province || !province.value.trim()) {
      showFieldError('province', 'Provinsi wajib diisi.');
      isValid = false;
    }

    if (!city || !city.value.trim()) {
      showFieldError('city', 'Kota / Kabupaten wajib diisi.');
      isValid = false;
    }

    if (!district || !district.value.trim()) {
      showFieldError('district', 'Kecamatan wajib diisi.');
      isValid = false;
    }

    if (!address || !address.value.trim()) {
      showFieldError('address', 'Alamat lengkap / nama jalan / RT RW wajib diisi.');
      isValid = false;
    }

    return isValid;
  }

  function validateStep2() {
    let isValid = true;
    const coverLetter = document.getElementById('cover_letter');

    clearError('resume');
    clearError('cover_letter');

    if (!state.selectedFile) {
      showFieldError('resume', 'Berkas CV (format PDF) wajib diunggah.');
      isValid = false;
    }

    if (!coverLetter || !coverLetter.value.trim()) {
      showFieldError('cover_letter', 'Catatan / surat lamaran singkat wajib diisi.');
      isValid = false;
    }

    return isValid;
  }

  function updateSummary() {
    const fullName = document.getElementById('full_name')?.value || '-';
    const gender = document.getElementById('gender')?.value || '';
    const email = document.getElementById('email')?.value || '-';
    const phone = document.getElementById('phone')?.value || '';
    const birthPlace = document.getElementById('birth_place')?.value || '';
    const birthDate = document.getElementById('birth_date')?.value || '';
    const nationality = document.getElementById('nationality')?.value || 'WNI';

    const edu = document.getElementById('education')?.value || '';
    const institution = document.getElementById('institution_name')?.value || '';
    const exp = document.getElementById('experience_years')?.value;
    const notice = document.getElementById('notice_period')?.value || '';

    const postalCode = document.getElementById('postal_code')?.value || '';
    const province = document.getElementById('province')?.value || '';
    const city = document.getElementById('city')?.value || '';
    const district = document.getElementById('district')?.value || '';
    const subdistrict = document.getElementById('subdistrict')?.value || '';
    const street = document.getElementById('address')?.value || '';

    if (elements.summaryName) {
      elements.summaryName.textContent = gender ? `${fullName} (${gender})` : fullName;
    }

    if (elements.summaryEmail) {
      const contactParts = [email];
      if (phone) contactParts.push(phone);
      elements.summaryEmail.textContent = contactParts.join(' • ');
    }

    if (elements.summaryEducation) {
      let details = [];
      if (birthPlace && birthDate) details.push(`TTL: ${birthPlace}, ${birthDate}`);
      if (nationality) details.push(nationality);
      if (edu) details.push(edu);
      if (institution) details.push(institution);
      if (exp !== undefined && exp !== '') details.push(`${exp} Thn Pengalaman`);
      else details.push('Fresh Graduate');
      if (notice) details.push(`Mulai: ${notice}`);
      
      const addressParts = [street, subdistrict ? `Kel. ${subdistrict}` : '', district ? `Kec. ${district}` : '', city, province, postalCode].filter(Boolean);
      if (addressParts.length > 0) details.push(`Domisili: ${addressParts.join(', ')}`);

      elements.summaryEducation.textContent = details.join(' • ');
    }
  }

  function showFieldError(fieldId, message) {
    const errorEl = document.getElementById(`error-${fieldId}`);
    const inputEl = document.getElementById(fieldId);
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.classList.remove('hidden');
    }
    if (inputEl) {
      inputEl.classList.add('border-rose-500');
    }
  }

  function clearError(fieldId) {
    const errorEl = document.getElementById(`error-${fieldId}`);
    const inputEl = document.getElementById(fieldId);
    if (errorEl) {
      errorEl.textContent = '';
      errorEl.classList.add('hidden');
    }
    if (inputEl) {
      inputEl.classList.remove('border-rose-500');
    }
  }

  // ── File Upload Handlers ───────────────────────────────────────────────────
  function handleFileSelected(file) {
    if (!file) return;

    clearError('resume');

    if (file.type !== 'application/pdf' && !file.name.toLowerCase().endsWith('.pdf')) {
      showFieldError('resume', 'Hanya file berkas berformat PDF yang diperbolehkan.');
      window.CareerUtils.showToast('Hanya file PDF yang diperbolehkan.', 'error');
      return;
    }

    if (file.size > window.CONFIG.MAX_FILE_SIZE) {
      showFieldError('resume', 'Ukuran file CV maksimal 5MB.');
      window.CareerUtils.showToast('Ukuran file CV maksimal 5MB.', 'error');
      return;
    }

    state.selectedFile = file;
    if (elements.fileName) elements.fileName.textContent = file.name;
    if (elements.fileSize) elements.fileSize.textContent = `${(file.size / (1024 * 1024)).toFixed(2)} MB`;

    elements.fileDropzone?.classList.add('hidden');
    elements.filePreview?.classList.remove('hidden');
    if (window.lucide) window.lucide.createIcons();
  }

  function removeSelectedFile() {
    state.selectedFile = null;
    if (elements.fileInput) elements.fileInput.value = '';
    elements.fileDropzone?.classList.remove('hidden');
    elements.filePreview?.classList.add('hidden');
  }

  // ── Submit Application ──────────────────────────────────────────────────────
  async function handleSubmit(e) {
    if (e) e.preventDefault();
    if (state.submitting) return;

    if (!validateStep1()) {
      setStep(1);
      return;
    }

    if (!validateStep2()) {
      setStep(2);
      return;
    }

    state.submitting = true;
    elements.btnSubmit.disabled = true;
    elements.btnSubmit.innerHTML = `
      <i data-lucide="loader-2" class="w-4 h-4 animate-spin inline-block mr-2"></i> Mengirimkan...
    `;
    if (window.lucide) window.lucide.createIcons();

    const formData = new FormData();
    formData.append('full_name', document.getElementById('full_name')?.value || '');
    formData.append('gender', document.getElementById('gender')?.value || '');
    formData.append('birth_place', document.getElementById('birth_place')?.value || '');
    formData.append('birth_date', document.getElementById('birth_date')?.value || '');
    formData.append('nationality', document.getElementById('nationality')?.value || 'WNI (Indonesia)');
    formData.append('email', document.getElementById('email')?.value || '');
    formData.append('phone', document.getElementById('phone')?.value || '');
    formData.append('postal_code', document.getElementById('postal_code')?.value || '');
    formData.append('province', document.getElementById('province')?.value || '');
    formData.append('city', document.getElementById('city')?.value || '');
    formData.append('district', document.getElementById('district')?.value || '');
    formData.append('subdistrict', document.getElementById('subdistrict')?.value || '');
    formData.append('address', document.getElementById('address')?.value || '');
    formData.append('education', document.getElementById('education')?.value || '');
    formData.append('institution_name', document.getElementById('institution_name')?.value || '');
    const expYears = document.getElementById('experience_years')?.value;
    if (expYears) formData.append('experience_years', expYears);
    formData.append('notice_period', document.getElementById('notice_period')?.value || '');
    formData.append('cover_letter', document.getElementById('cover_letter')?.value || '');

    if (state.selectedFile) {
      formData.append('resume', state.selectedFile);
    }

    try {
      await window.CareerAPI.applyJob(state.jobId, formData);

      // Show success screen
      if (elements.applyForm) elements.applyForm.classList.add('hidden');
      if (elements.formSuccess) elements.formSuccess.classList.remove('hidden');
      if (elements.applicantSuccessName) elements.applicantSuccessName.textContent = document.getElementById('full_name')?.value;
      if (elements.jobSuccessTitle) elements.jobSuccessTitle.textContent = state.job?.title;

      window.CareerUtils.showToast('Lamaran berhasil dikirim!', 'success');
      if (window.lucide) window.lucide.createIcons();
    } catch (err) {
      console.error(err);
      state.submitting = false;
      elements.btnSubmit.disabled = false;
      elements.btnSubmit.innerHTML = `
        <i data-lucide="send" class="w-4 h-4 inline-block mr-2"></i> Kirim Lamaran Sekarang
      `;

      if (err.errors) {
        Object.keys(err.errors).forEach(key => {
          showFieldError(key, err.errors[key][0]);
        });
        if (err.errors.full_name || err.errors.email || err.errors.phone || err.errors.postal_code || err.errors.gender) {
          setStep(1);
        }
      }

      window.CareerUtils.showToast(err.message || 'Gagal mengirim lamaran.', 'error');
      if (window.lucide) window.lucide.createIcons();
    }
  }

  // ── Attach Event Listeners ──────────────────────────────────────────────────
  elements.btnNextStep?.addEventListener('click', () => {
    if (state.currentStep === 1) {
      if (validateStep1()) setStep(2);
    }
  });

  elements.btnPrevStep?.addEventListener('click', () => {
    if (state.currentStep > 1) {
      setStep(state.currentStep - 1);
    }
  });

  elements.applyForm?.addEventListener('submit', handleSubmit);

  // Kode Pos Auto-detect input listener (Debounced)
  elements.postalCodeInput?.addEventListener('input', (e) => {
    const val = e.target.value;
    clearTimeout(state.postalLookupTimer);
    state.postalLookupTimer = setTimeout(() => {
      handlePostalCodeLookup(val);
    }, 350);
  });

  // File drag & drop
  if (elements.fileDropzone && elements.fileInput) {
    elements.fileDropzone.addEventListener('click', () => elements.fileInput.click());

    elements.fileInput.addEventListener('change', (e) => {
      const file = e.target.files?.[0];
      handleFileSelected(file);
    });

    elements.fileDropzone.addEventListener('dragover', (e) => {
      e.preventDefault();
      elements.fileDropzone.classList.add('border-brand-500', 'bg-brand-500/10');
    });

    elements.fileDropzone.addEventListener('dragleave', (e) => {
      e.preventDefault();
      elements.fileDropzone.classList.remove('border-brand-500', 'bg-brand-500/10');
    });

    elements.fileDropzone.addEventListener('drop', (e) => {
      e.preventDefault();
      elements.fileDropzone.classList.remove('border-brand-500', 'bg-brand-500/10');
      const file = e.dataTransfer.files?.[0];
      handleFileSelected(file);
    });
  }

  elements.fileRemoveBtn?.addEventListener('click', removeSelectedFile);

  // Initial load
  loadJobDetail();
});
