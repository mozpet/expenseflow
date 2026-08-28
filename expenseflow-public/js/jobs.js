/**
 * ExpenseFlow Career Portal - Job List Controller (index.html)
 */
document.addEventListener('DOMContentLoaded', () => {
  const state = {
    search: '',
    employment_type: '',
    page: 1,
    per_page: 9,
    totalPages: 1,
    totalJobs: 0,
    debounceTimer: null,
  };

  const elements = {
    searchInput: document.getElementById('search-input'),
    filterBtns: document.querySelectorAll('.filter-btn'),
    jobsContainer: document.getElementById('jobs-container'),
    jobsCount: document.getElementById('jobs-count'),
    paginationContainer: document.getElementById('pagination-container'),
    companyNameNav: document.getElementById('company-name-nav'),
    companyNameHero: document.getElementById('company-name-hero'),
    totalJobsBadge: document.getElementById('total-jobs-badge'),
  };

  // Inisialisasi Nama Perusahaan
  if (elements.companyNameNav) elements.companyNameNav.textContent = window.CONFIG.COMPANY_NAME || 'ExpenseFlow';
  if (elements.companyNameHero) elements.companyNameHero.textContent = window.CONFIG.COMPANY_NAME || 'ExpenseFlow';

  // ── Render Helpers ──────────────────────────────────────────────────────────
  function renderSkeleton() {
    if (!elements.jobsContainer) return;
    elements.jobsContainer.innerHTML = Array(6).fill(0).map(() => `
      <div class="glass-card rounded-2xl p-6 flex flex-col justify-between h-[250px]">
        <div>
          <div class="flex items-center justify-between mb-4">
            <div class="skeleton w-10 h-10 rounded-xl"></div>
            <div class="skeleton w-20 h-6 rounded-full"></div>
          </div>
          <div class="skeleton w-3/4 h-6 rounded mb-2"></div>
          <div class="skeleton w-1/2 h-4 rounded mb-4"></div>
        </div>
        <div class="pt-4 border-t border-white/5 flex items-center justify-between">
          <div class="skeleton w-24 h-4 rounded"></div>
          <div class="skeleton w-20 h-4 rounded"></div>
        </div>
      </div>
    `).join('');
  }

  function renderJobCard(job) {
    const badgeClass = window.CareerUtils.getEmploymentBadgeClass(job.employment_type);
    const empTypeLabel = window.CareerUtils.getEmploymentTypeLabel(job.employment_type);
    const companyInitial = (job.company?.name || window.CONFIG.COMPANY_NAME || 'E').charAt(0).toUpperCase();

    let salaryHtml = '';
    if (job.show_salary && (job.salary_min || job.salary_max)) {
      if (job.salary_min && job.salary_max) {
        salaryHtml = `<span class="text-xs font-semibold text-emerald-400">${window.CareerUtils.formatRupiah(job.salary_min)} - ${window.CareerUtils.formatRupiah(job.salary_max)}</span>`;
      } else if (job.salary_min) {
        salaryHtml = `<span class="text-xs font-semibold text-emerald-400">Mulai ${window.CareerUtils.formatRupiah(job.salary_min)}</span>`;
      }
    }

    // Format kualifikasi/persyaratan
    const reqLines = (job.requirements || '')
      .split('\n')
      .map(l => l.replace(/^[\s•\-\*\d\.\)\-]+/, '').trim())
      .filter(l => l.length > 0);

    let requirementsHtml = '';
    if (reqLines.length > 0) {
      requirementsHtml = `
        <div class="my-3.5 pt-3.5 border-t border-white/10 space-y-2">
          <div class="flex items-center justify-between">
            <span class="text-[11px] font-bold text-violet-400 uppercase tracking-wider flex items-center gap-1.5">
              <i data-lucide="check-square" class="w-3.5 h-3.5 text-violet-400"></i> Kualifikasi Utama
            </span>
            <span class="text-[10px] px-2 py-0.5 rounded-md bg-white/5 text-slate-400 border border-white/5">
              ${reqLines.length} Syarat
            </span>
          </div>
          <div class="space-y-1.5">
            ${reqLines.slice(0, 3).map(req => `
              <div class="flex items-start gap-2 text-xs text-slate-300">
                <i data-lucide="check" class="w-3.5 h-3.5 text-emerald-400 shrink-0 mt-0.5"></i>
                <span class="line-clamp-1 leading-snug">${window.CareerUtils.escapeHtml(req)}</span>
              </div>
            `).join('')}
            ${reqLines.length > 3 ? `
              <div class="text-[11px] text-violet-400 font-semibold pt-0.5 flex items-center gap-1">
                <span>+ ${reqLines.length - 3} kualifikasi lainnya</span>
              </div>
            ` : ''}
          </div>
        </div>
      `;
    } else if (job.description) {
      requirementsHtml = `
        <div class="my-3.5 pt-3.5 border-t border-white/10">
          <p class="text-xs text-slate-400 line-clamp-2 leading-relaxed">
            ${window.CareerUtils.escapeHtml(job.description)}
          </p>
        </div>
      `;
    }

    return `
      <a href="detail.html?id=${job.id}" class="glass-card glass-card-hover rounded-2xl p-6 flex flex-col justify-between group transition-all duration-300 text-left border border-white/10 hover:border-violet-500/40">
        <div>
          <!-- Header: Full Company Name & Employment Type -->
          <div class="flex items-center justify-between gap-2 mb-3.5">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-xl bg-violet-600/10 border border-violet-500/20 text-violet-300 text-xs font-bold truncate max-w-[200px]">
              <i data-lucide="building-2" class="w-3.5 h-3.5 text-violet-400 shrink-0"></i>
              <span class="truncate">${window.CareerUtils.escapeHtml(job.company?.name || window.CONFIG.COMPANY_NAME || 'PT Maju Bersama')}</span>
            </span>
            <span class="text-xs font-semibold px-2.5 py-0.5 rounded-full border shrink-0 ${badgeClass}">
              ${empTypeLabel}
            </span>
          </div>

          <!-- Job Title & Dept -->
          <h3 class="font-bold text-white text-lg group-hover:text-violet-400 transition-colors line-clamp-1 mb-1">
            ${window.CareerUtils.escapeHtml(job.title)}
          </h3>
          <p class="text-xs text-slate-400 mb-3 flex items-center gap-1.5 font-medium">
            <i data-lucide="layers" class="w-3.5 h-3.5 text-slate-500"></i>
            Departemen: <span class="text-slate-300 font-semibold">${window.CareerUtils.escapeHtml(job.department || 'Umum')}</span>
          </p>


          <!-- Badges / Location / Time -->
          <div class="flex flex-wrap gap-2 text-xs text-slate-400">
            ${job.location ? `
              <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-white/5 border border-white/5">
                <i data-lucide="map-pin" class="w-3 h-3 text-slate-400"></i>
                ${window.CareerUtils.escapeHtml(job.location)}
              </span>
            ` : ''}
            ${job.published_at ? `
              <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-white/5 border border-white/5">
                <i data-lucide="clock" class="w-3 h-3 text-slate-400"></i>
                ${window.CareerUtils.timeAgo(job.published_at)}
              </span>
            ` : ''}
            ${job.max_applicants ? `
              <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-purple-500/10 border border-purple-500/20 text-purple-300 font-medium">
                <i data-lucide="users" class="w-3 h-3 text-purple-400"></i>
                Kuota: ${job.max_applicants} Pelamar
              </span>
            ` : ''}
          </div>


          <!-- Kualifikasi / Persyaratan Section -->
          ${requirementsHtml}
        </div>

        <!-- Footer -->
        <div class="pt-4 border-t border-white/10 flex items-center justify-between mt-2">
          <div>
            ${salaryHtml ? salaryHtml : `
              <span class="text-xs text-slate-500">
                ${job.deadline ? `Batas: ${window.CareerUtils.formatShortDate(job.deadline)}` : 'Terbuka'}
              </span>
            `}
          </div>
          <span class="text-xs font-semibold text-violet-400 group-hover:text-violet-300 flex items-center gap-1">
            Lamar Sekarang <i data-lucide="arrow-right" class="w-3.5 h-3.5 transition-transform group-hover:translate-x-1"></i>
          </span>
        </div>
      </a>
    `;

  }

  function renderPagination(meta) {
    if (!elements.paginationContainer) return;
    if (!meta || meta.last_page <= 1) {
      elements.paginationContainer.innerHTML = '';
      return;
    }

    state.totalPages = meta.last_page;
    state.page = meta.current_page;

    elements.paginationContainer.innerHTML = `
      <div class="flex items-center justify-center gap-2 mt-10">
        <button id="prev-page-btn" ${state.page <= 1 ? 'disabled' : ''} class="px-3.5 py-2 rounded-xl text-sm font-medium glass-card hover:bg-white/10 text-slate-300 disabled:opacity-40 disabled:pointer-events-none transition-all flex items-center gap-1">
          <i data-lucide="chevron-left" class="w-4 h-4"></i> Sebelumnya
        </button>
        <span class="text-xs text-slate-400 font-medium px-4">
          Halaman <strong class="text-white">${state.page}</strong> dari <strong class="text-white">${state.totalPages}</strong>
        </span>
        <button id="next-page-btn" ${state.page >= state.totalPages ? 'disabled' : ''} class="px-3.5 py-2 rounded-xl text-sm font-medium glass-card hover:bg-white/10 text-slate-300 disabled:opacity-40 disabled:pointer-events-none transition-all flex items-center gap-1">
          Berikutnya <i data-lucide="chevron-right" class="w-4 h-4"></i>
        </button>
      </div>
    `;

    document.getElementById('prev-page-btn')?.addEventListener('click', () => {
      if (state.page > 1) {
        state.page--;
        loadJobs();
      }
    });

    document.getElementById('next-page-btn')?.addEventListener('click', () => {
      if (state.page < state.totalPages) {
        state.page++;
        loadJobs();
      }
    });
  }

  // ── Load Jobs from API ──────────────────────────────────────────────────────
  async function loadJobs() {
    renderSkeleton();

    try {
      const response = await window.CareerAPI.getJobs({
        search: state.search,
        employment_type: state.employment_type,
        page: state.page,
        per_page: state.per_page,
      });

      const jobs = response.data || [];
      const meta = response.meta || {};

      state.totalJobs = meta.total || jobs.length;

      if (elements.totalJobsBadge) {
        elements.totalJobsBadge.innerHTML = `
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
          ${state.totalJobs} Lowongan Tersedia
        `;
      }
      if (jobs.length > 0 && jobs[0].company?.name) {

        const compName = jobs[0].company.name;
        if (elements.companyNameNav) elements.companyNameNav.textContent = compName;
        if (elements.companyNameHero) elements.companyNameHero.textContent = compName;
      }

      if (jobs.length === 0) {
        elements.jobsContainer.innerHTML = `
          <div class="col-span-full text-center py-16 px-4">
            <div class="w-16 h-16 rounded-2xl bg-white/5 border border-white/10 flex items-center justify-center mx-auto mb-4 text-slate-500">
              <i data-lucide="briefcase" class="w-8 h-8"></i>
            </div>
            <h3 class="text-lg font-bold text-white mb-1">Belum Ada Lowongan</h3>
            <p class="text-sm text-slate-400 max-w-md mx-auto">
              Tidak ditemukan lowongan dengan filter yang dipilih. Coba cari dengan kata kunci lain.
            </p>
          </div>
        `;
      } else {
        elements.jobsContainer.innerHTML = jobs.map(renderJobCard).join('');
      }


      renderPagination(meta);

      if (window.lucide) window.lucide.createIcons();
    } catch (err) {
      console.error(err);
      elements.jobsContainer.innerHTML = `
        <div class="col-span-full text-center py-16 px-4">
          <div class="w-16 h-16 rounded-2xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center mx-auto mb-4 text-rose-400">
            <i data-lucide="alert-triangle" class="w-8 h-8"></i>
          </div>
          <h3 class="text-lg font-bold text-rose-400 mb-1">Gagal Memuat Lowongan</h3>
          <p class="text-sm text-slate-400 mb-4">${err.message || 'Terjadi kesalahan pada server.'}</p>
          <button id="retry-btn" class="px-4 py-2 bg-violet-600 hover:bg-violet-500 text-white rounded-xl text-sm font-semibold transition-all">
            Coba Lagi
          </button>
        </div>
      `;
      document.getElementById('retry-btn')?.addEventListener('click', loadJobs);
      if (window.lucide) window.lucide.createIcons();
    }
  }

  // ── Event Listeners ─────────────────────────────────────────────────────────
  if (elements.searchInput) {
    elements.searchInput.addEventListener('input', (e) => {
      clearTimeout(state.debounceTimer);
      state.debounceTimer = setTimeout(() => {
        state.search = e.target.value;
        state.page = 1;
        loadJobs();
      }, 400);
    });
  }

  elements.filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      elements.filterBtns.forEach(b => {
        b.classList.remove('bg-violet-600', 'text-white', 'border-violet-500');
        b.classList.add('bg-white/5', 'text-slate-400', 'border-white/10');
      });

      btn.classList.remove('bg-white/5', 'text-slate-400', 'border-white/10');
      btn.classList.add('bg-violet-600', 'text-white', 'border-violet-500');

      state.employment_type = btn.dataset.type || '';
      state.page = 1;
      loadJobs();
    });
  });

  // Initial load
  loadJobs();
});
