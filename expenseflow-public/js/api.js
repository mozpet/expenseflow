/**
 * ExpenseFlow Career Portal - API Client & Utility Functions
 */
const CareerAPI = {
  /**
   * Internal fetch with fallback between localhost and 127.0.0.1
   */
  async request(path, options = {}) {
    const primaryUrl = `${window.CONFIG.API_BASE_URL}${path}`;
    try {
      const res = await fetch(primaryUrl, options);
      return res;
    } catch (err) {
      // Fallback jika localhost gagal resolusi IPv6 vs IPv4
      if (window.CONFIG.API_BASE_URL.includes('localhost:8000')) {
        const fallbackUrl = window.CONFIG.API_BASE_URL.replace('localhost:8000', '127.0.0.1:8000') + path;
        try {
          return await fetch(fallbackUrl, options);
        } catch (e) {
          throw err;
        }
      } else if (window.CONFIG.API_BASE_URL.includes('127.0.0.1:8000')) {
        const fallbackUrl = window.CONFIG.API_BASE_URL.replace('127.0.0.1:8000', 'localhost:8000') + path;
        try {
          return await fetch(fallbackUrl, options);
        } catch (e) {
          throw err;
        }
      }
      throw err;
    }
  },

  /**
   * Mengambil daftar lowongan terbuka dari backend
   */
  async getJobs({ search = '', employment_type = '', page = 1, per_page = 12 } = {}) {
    const params = new URLSearchParams({
      page: page.toString(),
      per_page: per_page.toString(),
    });

    if (window.CONFIG && window.CONFIG.COMPANY_ID) {
      params.append('company_id', window.CONFIG.COMPANY_ID.toString());
    }

    if (search && search.trim()) params.append('search', search.trim());
    if (employment_type) params.append('employment_type', employment_type);

    const response = await this.request(`/public/jobs?${params.toString()}`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
      },
    });

    if (!response.ok) {
      throw new Error(`Gagal memuat lowongan (Status: ${response.status})`);
    }

    return await response.json();
  },

  /**
   * Mengambil detail satu lowongan
   */
  async getJobDetail(jobId) {
    const params = new URLSearchParams();
    if (window.CONFIG && window.CONFIG.COMPANY_ID) {
      params.append('company_id', window.CONFIG.COMPANY_ID.toString());
    }

    const response = await this.request(`/public/jobs/${jobId}?${params.toString()}`, {
      method: 'GET',
      headers: {
        'Accept': 'application/json',
      },
    });

    if (!response.ok) {
      if (response.status === 404) {
        throw new Error('Lowongan tidak ditemukan atau telah ditutup.');
      }
      throw new Error(`Gagal memuat detail lowongan (Status: ${response.status})`);
    }

    const res = await response.json();
    return res.data || res;
  },


  /**
   * Mengirim form lamaran ke backend (mendukung upload file PDF)
   */
  async applyJob(jobId, formData) {
    const response = await this.request(`/public/jobs/${jobId}/apply`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
      },
      body: formData,
    });

    const result = await response.json();

    if (!response.ok) {
      const error = new Error(result.message || 'Gagal mengirim lamaran.');
      error.errors = result.errors || {};
      error.status = response.status;
      throw error;
    }

    return result;
  },

  /**
   * Cari data wilayah (provinsi, kota, kecamatan, kelurahan) berdasarkan kode pos
   */
  async lookupPostalCode(code) {
    const clean = (code || '').trim().replace(/\D/g, '');
    if (!clean || clean.length < 3) return null;

    try {
      const response = await this.request(`/public/postal-code/${clean}`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
        },
      });

      if (response.ok) {
        const result = await response.json();
        if (result && result.found && result.data && result.data.length > 0) {
          return result.data;
        }
      }
    } catch (e) {
      // Fallback
    }
    return null;
  },
};


// ── Formatting Helpers ───────────────────────────────────────────────────────
const CareerUtils = {
  formatRupiah(amount) {
    if (amount == null || isNaN(amount)) return '';
    return new Intl.NumberFormat('id-ID', {
      style: 'currency',
      currency: 'IDR',
      maximumFractionDigits: 0,
    }).format(amount);
  },

  formatDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('id-ID', {
      day: 'numeric',
      month: 'long',
      year: 'numeric',
    });
  },

  formatShortDate(dateStr) {
    if (!dateStr) return '-';
    const d = new Date(dateStr);
    return d.toLocaleDateString('id-ID', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  },

  timeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = Date.now() - new Date(dateStr).getTime();
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    if (days === 0) return 'Hari ini';
    if (days === 1) return 'Kemarin';
    if (days < 7) return `${days} hari yang lalu`;
    if (days < 30) return `${Math.floor(days / 7)} minggu yang lalu`;
    return this.formatShortDate(dateStr);
  },

  getEmploymentTypeLabel(type) {
    const map = {
      full_time: 'Full Time',
      part_time: 'Part Time',
      contract: 'Kontrak',
      internship: 'Magang',
    };
    return map[type] || type || 'Full Time';
  },

  getEmploymentBadgeClass(type) {
    const map = {
      full_time: 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20',
      part_time: 'bg-blue-500/10 text-blue-400 border-blue-500/20',
      contract: 'bg-amber-500/10 text-amber-400 border-amber-500/20',
      internship: 'bg-purple-500/10 text-purple-400 border-purple-500/20',
    };
    return map[type] || 'bg-slate-500/10 text-slate-400 border-slate-500/20';
  },

  escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  },

  showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toast = document.createElement('div');
    const isSuccess = type === 'success';

    toast.className = `flex items-center gap-3 px-4 py-3 rounded-xl shadow-2xl text-sm font-medium border transition-all duration-300 transform translate-y-2 opacity-0 ${
      isSuccess
        ? 'bg-emerald-950/90 text-emerald-300 border-emerald-800'
        : 'bg-rose-950/90 text-rose-300 border-rose-800'
    }`;

    toast.innerHTML = `
      <i data-lucide="${isSuccess ? 'check-circle' : 'alert-circle'}" class="w-5 h-5 shrink-0"></i>
      <div class="flex-1">${this.escapeHtml(message)}</div>
    `;

    container.appendChild(toast);
    if (window.lucide) window.lucide.createIcons();

    setTimeout(() => {
      toast.classList.remove('translate-y-2', 'opacity-0');
    }, 10);

    setTimeout(() => {
      toast.classList.add('opacity-0', '-translate-y-2');
      setTimeout(() => toast.remove(), 300);
    }, 4000);
  },
};

window.CareerAPI = CareerAPI;
window.CareerUtils = CareerUtils;
