import React, { useState, useEffect, useCallback, useRef } from 'react';
import {
  Briefcase, Plus, Search, Eye, Trash2, CheckCircle, XCircle, Clock,
  Users, FileText, Download, Edit3, Send, X, AlertCircle, Loader2,
  Globe, Lock, Building2, MapPin, Calendar, DollarSign, Award,
  ChevronLeft, MoreVertical, RefreshCw, User, Mail, Phone, Home,
  GraduationCap, Check, ArrowRight, List, AlignLeft, CheckSquare,
  Copy, ExternalLink, Sparkles, Video, HelpCircle, ArrowLeft,
  Printer, FileCheck, CheckCheck
} from 'lucide-react';
import { recruitmentApi } from '../services/endpoints';
import { ApiError, getStoredUser } from '../services/api';
import CustomDatePicker from './CustomDatePicker';
import CustomTimePicker from './CustomTimePicker';


// ── Types ────────────────────────────────────────────────────────────────────

interface JobPosting {
  id: number;
  company_id: number;
  created_by: number;
  title: string;
  department: string | null;
  location: string | null;
  employment_type: 'full_time' | 'part_time' | 'contract' | 'internship';
  description: string;
  requirements: string | null;
  salary_min: number | null;
  salary_max: number | null;
  show_salary: boolean;
  max_applicants?: number | null;
  contact_email?: string | null;
  status: 'draft' | 'open' | 'closed';
  deadline: string | null;
  published_at: string | null;
  created_at?: string;
  applications_count?: number;
  new_count?: number;
  shortlisted_count?: number;
  hired_count?: number;
  creator?: { id: number; name: string };
  company?: { id: number; name: string };
}

interface JobApplication {
  id: number;
  job_posting_id: number;
  full_name: string;
  gender?: string | null;
  birth_place?: string | null;
  birth_date?: string | null;
  nationality?: string | null;
  email: string;
  phone: string | null;
  postal_code?: string | null;
  province?: string | null;
  city?: string | null;
  district?: string | null;
  subdistrict?: string | null;
  address: string | null;
  education: string | null;
  institution_name?: string | null;
  experience_years: number | null;
  notice_period?: string | null;

  expected_salary?: number | null;
  portfolio_url?: string | null;
  cover_letter: string | null;
  has_resume: boolean;
  status: 'new' | 'reviewed' | 'shortlisted' | 'rejected' | 'hired';
  status_label?: string;
  notes: string | null;
  offering_details?: any | null;
  reviewed_by: number | null;
  reviewed_at: string | null;
  created_at: string;
  reviewer?: { id: number; name: string };
  job_posting?: {
    id: number;
    title: string;
    department: string | null;
    status?: string;
    salary_min?: number | null;
    salary_max?: number | null;
    contact_email?: string | null;
    company?: { id: number; name: string };
  };

}

interface ApiMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

type MainTab = 'postings' | 'applications';
type ViewMode = 'list' | 'form' | 'posting-detail' | 'applicant-detail';

// ── Constants & Helpers ──────────────────────────────────────────────────────

const STATUS_CONFIG = {
  draft:  { label: 'Draft',  cls: 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800/60', icon: Lock },
  open:   { label: 'Buka',   cls: 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800/60', icon: Globe },
  closed: { label: 'Tutup',  cls: 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800/60', icon: XCircle },
};

const APP_STATUS_CONFIG = {
  new:         { label: 'Baru',      cls: 'bg-blue-50 dark:bg-blue-950/40 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800/60' },
  reviewed:    { label: 'Ditinjau', cls: 'bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800/60' },
  shortlisted: { label: 'Shortlist', cls: 'bg-indigo-50 dark:bg-indigo-950/40 text-indigo-700 dark:text-indigo-300 border-indigo-200 dark:border-indigo-800/60' },
  rejected:    { label: 'Ditolak',  cls: 'bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800/60' },
  hired:       { label: 'Diterima', cls: 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800/60' },
};

const EMP_TYPE_LABELS: Record<string, string> = {
  full_time:  'Full Time',
  part_time:  'Part Time',
  contract:   'Kontrak',
  internship: 'Magang',
};

function formatRupiah(n: number | null | undefined): string {
  if (n == null || isNaN(n)) return '-';
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(n);
}

function formatDate(d: string | null | undefined): string {
  if (!d) return '-';
  return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

// ── Main Component ────────────────────────────────────────────────────────────

export function RecruitmentManagement() {
  // Tabs & Views
  const [activeTab, setActiveTab] = useState<MainTab>('postings');
  const [viewMode, setViewMode]   = useState<ViewMode>('list');

  // Selected Entities
  const [selectedPosting, setSelectedPosting]         = useState<JobPosting | null>(null);
  const [selectedApplication, setSelectedApplication] = useState<JobApplication | null>(null);

  // Postings State
  const [postings, setPostings]               = useState<JobPosting[]>([]);
  const [postingsMeta, setPostingsMeta]       = useState<ApiMeta | null>(null);
  const [postingsSummary, setPostingsSummary] = useState<Record<string, number>>({});
  const [loadingPostings, setLoadingPostings] = useState(false);
  const [statusFilter, setStatusFilter]       = useState('');
  const [searchQuery, setSearchQuery]         = useState('');
  const [currentPage, setCurrentPage]         = useState(1);

  // Applications State
  const [applications, setApplications]         = useState<JobApplication[]>([]);
  const [applicationsMeta, setApplicationsMeta] = useState<ApiMeta | null>(null);
  const [appsSummary, setAppsSummary]           = useState<Record<string, number>>({});
  const [loadingApps, setLoadingApps]           = useState(false);
  const [appStatusFilter, setAppStatusFilter]   = useState('');
  const [appSearch, setAppSearch]               = useState('');
  const [appPage, setAppPage]                   = useState(1);
  const [filterPostingId, setFilterPostingId]   = useState<number | null>(null);
  
  // Modal Candidate States
  const [interviewCandidate, setInterviewCandidate] = useState<JobApplication | null>(null);
  const [rejectionCandidate, setRejectionCandidate] = useState<JobApplication | null>(null);
  const [offeringCandidate, setOfferingCandidate]   = useState<JobApplication | null>(null);

  // Global notifications & loading
  const [error, setError]       = useState('');
  const [success, setSuccess]   = useState('');
  const [actionLoading, setActionLoading] = useState(false);

  // Form State
  const [formMode, setFormMode]     = useState<'create' | 'edit'>('create');
  const [formData, setFormData]     = useState<Partial<JobPosting>>({
    title: '',
    department: '',
    location: '',
    employment_type: 'full_time',
    description: '',
    requirements: '',
    salary_min: null,
    salary_max: null,
    show_salary: false,
    max_applicants: null,
    contact_email: '',
    status: 'draft',
    deadline: null,
  });
  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // ── Auto-clear flash messages ──────────────────────────────────────────────
  useEffect(() => {
    if (success) {
      const t = setTimeout(() => setSuccess(''), 3500);
      return () => clearTimeout(t);
    }
  }, [success]);

  useEffect(() => {
    if (error) {
      const t = setTimeout(() => setError(''), 5000);
      return () => clearTimeout(t);
    }
  }, [error]);

  // ── Load Postings ──────────────────────────────────────────────────────────
  const fetchPostings = useCallback(async () => {
    setLoadingPostings(true);
    try {
      const res = await recruitmentApi.listPostings({
        page: currentPage,
        per_page: 12,
        status: statusFilter || undefined,
        search: searchQuery.trim() || undefined,
      });
      setPostings(res.data ?? []);
      setPostingsMeta(res.meta ?? null);
      setPostingsSummary(res.summary ?? {});
    } catch (err: any) {
      setError(err?.message || 'Gagal memuat lowongan kerja.');
    } finally {
      setLoadingPostings(false);
    }
  }, [currentPage, statusFilter, searchQuery]);

  // ── Load Applications ──────────────────────────────────────────────────────
  const fetchApplications = useCallback(async () => {
    setLoadingApps(true);
    try {
      const res = await recruitmentApi.listApplications(
        filterPostingId || undefined,
        {
          page: appPage,
          per_page: 20,
          status: appStatusFilter || undefined,
          search: appSearch.trim() || undefined,
        }
      );
      setApplications(res.data ?? []);
      setApplicationsMeta(res.meta ?? null);
      if (res.summary) setAppsSummary(res.summary);
    } catch (err: any) {
      setError(err?.message || 'Gagal memuat daftar pelamar.');
    } finally {
      setLoadingApps(false);
    }
  }, [appPage, appStatusFilter, appSearch, filterPostingId]);

  // Trigger loads based on activeTab & viewMode
  useEffect(() => {
    if (activeTab === 'postings' && viewMode === 'list') {
      fetchPostings();
    } else if (activeTab === 'applications' && viewMode === 'list') {
      fetchApplications();
    }
  }, [activeTab, viewMode, fetchPostings, fetchApplications]);

  // ── Posting Actions ────────────────────────────────────────────────────────
  const handlePublish = async (posting: JobPosting) => {
    if (!confirm(`Publikasikan lowongan "${posting.title}" ke portal karir publik?`)) return;
    try {
      const res = await recruitmentApi.publishPosting(posting.id);
      setSuccess(res.message || 'Lowongan berhasil dipublikasikan!');
      fetchPostings();
      if (selectedPosting?.id === posting.id) {
        setSelectedPosting({ ...selectedPosting, status: 'open' });
      }
    } catch (err: any) {
      setError(err?.message || 'Gagal mempublikasikan lowongan.');
    }
  };

  const handleClose = async (posting: JobPosting) => {
    if (!confirm(`Tutup lowongan "${posting.title}"? Pelamar tidak dapat mendaftar lagi.`)) return;
    try {
      const res = await recruitmentApi.closePosting(posting.id);
      setSuccess(res.message || 'Lowongan berhasil ditutup.');
      fetchPostings();
      if (selectedPosting?.id === posting.id) {
        setSelectedPosting({ ...selectedPosting, status: 'closed' });
      }
    } catch (err: any) {
      setError(err?.message || 'Gagal menutup lowongan.');
    }
  };

  const handleDelete = async (posting: JobPosting) => {
    if (!confirm(`Hapus lowongan "${posting.title}"? Tindakan ini tidak dapat dibatalkan.`)) return;
    try {
      const res = await recruitmentApi.deletePosting(posting.id);
      setSuccess(res.message || 'Lowongan berhasil dihapus.');
      setViewMode('list');
      fetchPostings();
    } catch (err: any) {
      setError(err?.message || 'Gagal menghapus lowongan.');
    }
  };

  const openCreateForm = () => {
    const user = getStoredUser();
    setFormMode('create');
    setFormData({
      title: '',
      department: '',
      location: '',
      employment_type: 'full_time',
      description: '',
      requirements: '',
      salary_min: null,
      salary_max: null,
      show_salary: false,
      max_applicants: null,
      contact_email: user?.email || '',
      status: 'draft',
      deadline: null,
    });
    setFormErrors({});
    setViewMode('form');
  };

  const openEditForm = (posting: JobPosting) => {
    setFormMode('edit');
    setSelectedPosting(posting);
    setFormData({
      title: posting.title || '',
      department: posting.department || '',
      location: posting.location || '',
      employment_type: posting.employment_type || 'full_time',
      description: posting.description || '',
      requirements: posting.requirements || '',
      salary_min: posting.salary_min ?? null,
      salary_max: posting.salary_max ?? null,
      show_salary: !!posting.show_salary,
      max_applicants: posting.max_applicants ?? null,
      contact_email: posting.contact_email ?? '',
      status: posting.status || 'draft',
      deadline: posting.deadline ? posting.deadline.split('T')[0] : null,
    });
    setFormErrors({});
    setViewMode('form');
  };

  const handleSubmitForm = async (e: React.FormEvent) => {
    e.preventDefault();
    setActionLoading(true);
    setFormErrors({});

    try {
      if (formMode === 'create') {
        const res = await recruitmentApi.createPosting(formData);
        setSuccess(res.message || 'Lowongan berhasil dibuat!');
      } else {
        const res = await recruitmentApi.updatePosting(selectedPosting!.id, formData);
        setSuccess(res.message || 'Lowongan berhasil diperbarui!');
      }
      setViewMode('list');
      fetchPostings();
    } catch (err: any) {
      if (err instanceof ApiError && err.data?.errors) {
        const flat: Record<string, string> = {};
        Object.keys(err.data.errors).forEach(k => {
          flat[k] = err.data.errors[k][0];
        });
        setFormErrors(flat);
      }
      setError(err?.message || 'Gagal menyimpan data lowongan.');
    } finally {
      setActionLoading(false);
    }
  };

  // ── Application Actions ────────────────────────────────────────────────────
  const handleUpdateAppStatus = async (appId: number, status: string, notes?: string) => {
    try {
      const res = await recruitmentApi.updateApplicationStatus(appId, { status, notes });
      setSuccess(res.message || 'Status pelamar berhasil diperbarui.');
      fetchApplications();
      fetchPostings();
      if (selectedApplication?.id === appId) {
        setSelectedApplication(prev => prev ? { ...prev, status: status as any, notes: notes !== undefined ? notes : prev.notes } : null);
      }
    } catch (err: any) {
      setError(err?.message || 'Gagal memperbarui status pelamar.');
    }
  };

  const handleDownloadResume = async (app: JobApplication) => {
    try {
      await recruitmentApi.viewResume(app.id, app.full_name);
      setSuccess(`Berkas CV ${app.full_name} berhasil dibuka di tab baru.`);

      
      // Jika statusnya sebelumnya 'new', sinkronkan langsung ke 'reviewed' di local state
      if (app.status === 'new') {
        setApplications(prev => prev.map(a => a.id === app.id ? { ...a, status: 'reviewed' } : a));
        if (selectedApplication?.id === app.id) {
          setSelectedApplication(prev => prev ? { ...prev, status: 'reviewed' } : null);
        }
        fetchPostings();
        fetchApplications();
      }
    } catch (err: any) {
      setError(err?.message || 'File CV tidak ditemukan di server.');
    }
  };


  const handleDeleteApplication = async (app: JobApplication) => {
    if (!window.confirm(`Yakin ingin menghapus berkas pelamar "${app.full_name}" yang telah ditolak? Tindakan ini akan menghapus data dan file CV secara permanen.`)) {
      return;
    }
    try {
      const res = await recruitmentApi.deleteApplication(app.id);
      setSuccess(res.message || `Data pelamar "${app.full_name}" berhasil dihapus.`);
      fetchApplications();
      fetchPostings();
      if (selectedApplication?.id === app.id) {
        setViewMode('list');
        setSelectedApplication(null);
      }
    } catch (err: any) {
      setError(err?.message || 'Gagal menghapus data pelamar.');
    }
  };

  return (
    <div className="space-y-6 font-sans">
      
      {/* Flash Toasts */}
      {(success || error) && (
        <div className={`fixed top-4 right-4 z-50 max-w-sm px-4 py-3 rounded-2xl shadow-xl flex items-start gap-3 text-sm font-medium border backdrop-blur-xl transition-all ${
          success 
            ? 'bg-emerald-50 dark:bg-emerald-950/90 text-emerald-700 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800 shadow-emerald-500/10' 
            : 'bg-rose-50 dark:bg-rose-950/90 text-rose-700 dark:text-rose-300 border-rose-200 dark:border-rose-800 shadow-rose-500/10'
        }`}>
          {success ? <CheckCircle size={18} className="mt-0.5 shrink-0 text-emerald-600 dark:text-emerald-400" /> : <AlertCircle size={18} className="mt-0.5 shrink-0 text-rose-600 dark:text-rose-400" />}
          <div className="flex-1 text-xs sm:text-sm">{success || error}</div>
          <button onClick={() => { setSuccess(''); setError(''); }} className="text-slate-400 hover:text-slate-700 dark:hover:text-white cursor-pointer">
            <X size={14} />
          </button>
        </div>
      )}

      {/* Main Top Header & Tab Switcher */}
      {viewMode === 'list' && (
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 shadow-xs">
          <div>
            <h3 className="text-sm font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
              <Briefcase className="w-5 h-5 text-indigo-600 shrink-0" />
              Rekrutmen &amp; Seleksi Karyawan
            </h3>
            <p className="text-xs text-slate-400 dark:text-slate-500 mt-1">
              Kelola lowongan pekerjaan perusahaan dan seleksi berkas kandidat pelamar secara terpadu.
            </p>
          </div>

          <div className="flex gap-2.5 w-full sm:w-auto shrink-0">
            <button 
              onClick={() => {
                if (activeTab === 'postings') fetchPostings();
                else fetchApplications();
              }}
              disabled={loadingPostings || loadingApps}
              className="flex-1 sm:flex-initial flex items-center justify-center gap-1.5 py-2 px-4 rounded-xl text-xs font-bold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-100 dark:border-indigo-800 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 transition duration-150 cursor-pointer disabled:opacity-50"
              title="Refresh Data"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${(loadingPostings || loadingApps) ? 'animate-spin' : ''}`} />
              <span>Refresh</span>
            </button>

            {activeTab === 'postings' && (
              <button 
                onClick={openCreateForm}
                className="flex-1 sm:flex-initial flex items-center justify-center gap-1.5 py-2 px-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm shadow-indigo-500/15 transition duration-150 cursor-pointer"
              >
                <Plus className="w-3.5 h-3.5" />
                <span>Buat Lowongan</span>
              </button>
            )}
          </div>
        </div>
      )}

      {/* Navigation Tabs */}
      {viewMode === 'list' && (
        <div className="flex items-center gap-2">
          <button
            onClick={() => { setActiveTab('postings'); setFilterPostingId(null); }}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all border cursor-pointer ${
              activeTab === 'postings'
                ? 'bg-indigo-600 text-white border-indigo-500 shadow-sm shadow-indigo-500/20'
                : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800'
            }`}
          >
            <Briefcase size={15} />
            <span>Lowongan Kerja</span>
            {postingsSummary.total != null && (
              <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                activeTab === 'postings' ? 'bg-indigo-700 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'
              }`}>
                {postingsSummary.total}
              </span>
            )}
          </button>

          <button
            onClick={() => { setActiveTab('applications'); setFilterPostingId(null); }}
            className={`flex items-center gap-2 px-4 py-2 rounded-xl text-xs sm:text-sm font-semibold transition-all border cursor-pointer ${
              activeTab === 'applications'
                ? 'bg-indigo-600 text-white border-indigo-500 shadow-sm shadow-indigo-500/20'
                : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-300 border-slate-200 dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800'
            }`}
          >
            <Users size={15} />
            <span>Semua Pelamar</span>
            {appsSummary.total != null && (
              <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                activeTab === 'applications' ? 'bg-indigo-700 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300'
              }`}>
                {appsSummary.total}
              </span>
            )}
          </button>
        </div>
      )}

      {/* ── VIEW: Postings List ── */}
      {viewMode === 'list' && activeTab === 'postings' && (
        <PostingsListView
          postings={postings}
          meta={postingsMeta}
          summary={postingsSummary}
          loading={loadingPostings}
          statusFilter={statusFilter}
          searchQuery={searchQuery}
          currentPage={currentPage}
          onStatusFilter={(s: string) => { setStatusFilter(s); setCurrentPage(1); }}
          onSearch={(s: string) => { setSearchQuery(s); setCurrentPage(1); }}
          onPageChange={setCurrentPage}
          onCreateNew={openCreateForm}
          onViewDetail={(p: JobPosting) => { setSelectedPosting(p); setViewMode('posting-detail'); }}
          onEditPosting={openEditForm}
          onPublish={handlePublish}
          onClose={handleClose}
          onDelete={handleDelete}
          onViewApplicants={(p: JobPosting) => {
            setFilterPostingId(p.id);
            setSelectedPosting(p);
            setActiveTab('applications');
          }}
        />
      )}

      {/* ── VIEW: Applications List ── */}
      {viewMode === 'list' && activeTab === 'applications' && (
        <ApplicationsListView
          filterPosting={selectedPosting}
          applications={applications}
          meta={applicationsMeta}
          summary={appsSummary}
          loading={loadingApps}
          statusFilter={appStatusFilter}
          searchQuery={appSearch}
          currentPage={appPage}
          onClearPostingFilter={() => { setFilterPostingId(null); setSelectedPosting(null); }}
          onStatusFilter={(s: string) => { setAppStatusFilter(s); setAppPage(1); }}
          onSearch={(s: string) => { setAppSearch(s); setAppPage(1); }}
          onPageChange={setAppPage}
          onViewDetail={(app: JobApplication) => { setSelectedApplication(app); setViewMode('applicant-detail'); }}
          onUpdateStatus={handleUpdateAppStatus}
          onDownloadResume={handleDownloadResume}
          onDeleteApplication={handleDeleteApplication}
          onOpenInterviewModal={(app: JobApplication) => setInterviewCandidate(app)}
          onOpenRejectionModal={(app: JobApplication) => setRejectionCandidate(app)}
          onOpenOfferingModal={(app: JobApplication) => setOfferingCandidate(app)}
        />
      )}

      {/* ── VIEW: Posting Create / Edit Form ── */}
      {viewMode === 'form' && (
        <PostingFormView
          mode={formMode}
          data={formData}
          errors={formErrors}
          loading={actionLoading}
          onBack={() => setViewMode('list')}
          onChange={(k: keyof JobPosting, v: any) => setFormData(prev => ({ ...prev, [k]: v }))}
          onSubmit={handleSubmitForm}
        />
      )}

      {/* ── VIEW: Posting Detail ── */}
      {viewMode === 'posting-detail' && selectedPosting && (
        <PostingDetailView
          posting={selectedPosting}
          onBack={() => setViewMode('list')}
          onEdit={() => openEditForm(selectedPosting)}
          onPublish={() => handlePublish(selectedPosting)}
          onClose={() => handleClose(selectedPosting)}
          onDelete={() => handleDelete(selectedPosting)}
          onViewApplicants={() => {
            setFilterPostingId(selectedPosting.id);
            setActiveTab('applications');
            setViewMode('list');
          }}
        />
      )}

      {/* ── VIEW: Applicant Detail ── */}
      {viewMode === 'applicant-detail' && selectedApplication && (
        <ApplicantDetailView
          application={selectedApplication}
          onBack={() => setViewMode('list')}
          onUpdateStatus={(status: string, notes?: string) => handleUpdateAppStatus(selectedApplication.id, status, notes)}
          onDownloadResume={() => handleDownloadResume(selectedApplication)}
          onDeleteApplication={() => handleDeleteApplication(selectedApplication)}
          onOpenInterviewModal={() => setInterviewCandidate(selectedApplication)}
          onOpenRejectionModal={() => setRejectionCandidate(selectedApplication)}
          onOpenOfferingModal={() => setOfferingCandidate(selectedApplication)}
        />
      )}

      {/* ── MODAL: Undangan Interview ── */}
      {interviewCandidate && (
        <InterviewInvitationModal
          application={interviewCandidate}
          onClose={() => setInterviewCandidate(null)}
          onSaveNotes={async (noteText: string) => {
            await handleUpdateAppStatus(interviewCandidate.id, interviewCandidate.status, noteText);
          }}
          showToast={(msg: string, type: 'success' | 'error' = 'success') => {
            if (type === 'error') setError(msg);
            else setSuccess(msg);
          }}
        />
      )}

      {/* ── MODAL: Surat Penolakan Sopan (Rejection Letter) ── */}
      {rejectionCandidate && (
        <RejectionEmailModal
          application={rejectionCandidate}
          onClose={() => setRejectionCandidate(null)}
          onMarkRejected={async () => {
            await handleUpdateAppStatus(rejectionCandidate.id, 'rejected');
          }}
          showToast={(msg: string, type: 'success' | 'error' = 'success') => {
            if (type === 'error') setError(msg);
            else setSuccess(msg);
          }}
        />
      )}

      {/* ── MODAL: Surat Penawaran Kerja (Offering Letter) ── */}
      {offeringCandidate && (
        <OfferingLetterModal
          application={offeringCandidate}
          onClose={() => setOfferingCandidate(null)}
          onSaveOffering={async (offeringText: string) => {
            await handleUpdateAppStatus(offeringCandidate.id, offeringCandidate.status, offeringText);
          }}
          showToast={(msg: string, type: 'success' | 'error' = 'success') => {
            if (type === 'error') setError(msg);
            else setSuccess(msg);
          }}
        />
      )}

    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// SUB-COMPONENTS
// ─────────────────────────────────────────────────────────────────────────────

// 1. PostingsListView
function PostingsListView({
  postings, meta, summary, loading, statusFilter, searchQuery, currentPage,
  onStatusFilter, onSearch, onPageChange, onCreateNew, onViewDetail,
  onEditPosting, onPublish, onClose, onDelete, onViewApplicants
}: any) {
  const [localSearch, setLocalSearch] = useState(searchQuery);
  const searchTimer = useRef<ReturnType<typeof setTimeout>>();

  const handleSearchChange = (val: string) => {
    setLocalSearch(val);
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => onSearch(val), 400);
  };

  const statCards = [
    { key: '',       label: 'Total Lowongan', value: summary.total ?? 0,  color: 'text-slate-800 dark:text-slate-100', desc: 'Semua posisi lowongan' },
    { key: 'open',   label: 'Aktif / Buka',   value: summary.open ?? 0,   color: 'text-emerald-600 dark:text-emerald-400', desc: 'Tayang di portal publik' },
    { key: 'draft',  label: 'Draft',          value: summary.draft ?? 0,  color: 'text-amber-600 dark:text-amber-400', desc: 'Belum dipublikasikan' },
    { key: 'closed', label: 'Tutup',          value: summary.closed ?? 0, color: 'text-rose-600 dark:text-rose-400', desc: 'Pendaftaran ditutup' },
  ];

  return (
    <div className="space-y-6">
      {/* Bento Stat Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        {statCards.map(s => (
          <div
            key={s.label}
            onClick={() => onStatusFilter(s.key)}
            className={`p-4 rounded-2xl border transition-all cursor-pointer flex flex-col justify-between ${
              statusFilter === s.key
                ? 'bg-indigo-50/50 dark:bg-indigo-950/30 border-indigo-200 dark:border-indigo-800 ring-2 ring-indigo-500/20'
                : 'bg-white dark:bg-slate-900 border-slate-100 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 shadow-xs'
            }`}
          >
            <div className="flex items-center justify-between">
              <span className="text-[11px] font-bold text-slate-400 uppercase tracking-widest block">{s.label}</span>
              {s.key === 'open' && <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse" />}
            </div>
            <div className="mt-2 flex items-baseline gap-1.5">
              <span className={`text-2xl font-black font-mono ${s.color}`}>{s.value}</span>
              <span className="text-[10px] text-slate-400 font-medium">posisi</span>
            </div>
            <span className="text-[9px] text-slate-400 dark:text-slate-500 mt-1 block">{s.desc}</span>
          </div>
        ))}
      </div>

      {/* Main Container */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-5 shadow-xs space-y-5">
        {/* Filter & Search Bar */}
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="relative flex-1">
            <Search size={15} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              value={localSearch}
              onChange={e => handleSearchChange(e.target.value)}
              placeholder="Cari judul posisi, departemen, atau lokasi..."
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl pl-10 pr-4 py-2.5 text-xs sm:text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div className="flex flex-wrap gap-1.5">
            {[
              { key: '', label: 'Semua Status' },
              { key: 'open', label: 'Buka' },
              { key: 'draft', label: 'Draft' },
              { key: 'closed', label: 'Tutup' },
            ].map(f => (
              <button
                key={f.key}
                onClick={() => onStatusFilter(f.key)}
                className={`px-3 py-2 rounded-xl text-xs font-semibold transition-all border cursor-pointer ${
                  statusFilter === f.key
                    ? 'bg-indigo-600 text-white border-indigo-500 shadow-sm'
                    : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'
                }`}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>

        {/* Postings Grid */}
        {loading ? (
          <div className="flex flex-col items-center justify-center py-20 gap-3">
            <Loader2 className="animate-spin text-indigo-600" size={32} />
            <span className="text-xs text-slate-400">Memuat data lowongan...</span>
          </div>
        ) : postings.length === 0 ? (
          <div className="text-center py-16 bg-slate-50/50 dark:bg-slate-950/40 border border-slate-100 dark:border-slate-800/80 rounded-3xl p-6">
            <Briefcase size={48} className="mx-auto text-slate-400 mb-3" />
            <h3 className="text-base font-bold text-slate-800 dark:text-white mb-1">Belum Ada Lowongan</h3>
            <p className="text-xs text-slate-400 max-w-sm mx-auto mb-4">
              Buat lowongan pekerjaan baru untuk mulai menerima lamaran dari kandidat.
            </p>
            <button
              onClick={onCreateNew}
              className="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-sm shadow-indigo-500/15 transition-all cursor-pointer"
            >
              + Buat Lowongan Baru
            </button>
          </div>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {postings.map((p: JobPosting) => (
              <PostingCard
                key={p.id}
                posting={p}
                onView={() => onViewDetail(p)}
                onEdit={() => onEditPosting(p)}
                onPublish={() => onPublish(p)}
                onClose={() => onClose(p)}
                onDelete={() => onDelete(p)}
                onViewApplicants={() => onViewApplicants(p)}
              />
            ))}
          </div>
        )}

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-center gap-2 pt-4 border-t border-slate-100 dark:border-slate-800">
            <button
              onClick={() => onPageChange(currentPage - 1)}
              disabled={currentPage <= 1}
              className="px-3.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 disabled:opacity-40 text-xs border border-slate-200 dark:border-slate-700 cursor-pointer"
            >
              &larr; Prev
            </button>
            <span className="text-xs text-slate-500 px-2 font-medium">
              Halaman {currentPage} dari {meta.last_page}
            </span>
            <button
              onClick={() => onPageChange(currentPage + 1)}
              disabled={currentPage >= meta.last_page}
              className="px-3.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 disabled:opacity-40 text-xs border border-slate-200 dark:border-slate-700 cursor-pointer"
            >
              Next &rarr;
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// 2. PostingCard
function PostingCard({ posting, onView, onEdit, onPublish, onClose, onDelete, onViewApplicants }: any) {
  const [menuOpen, setMenuOpen] = useState(false);
  const cfg = STATUS_CONFIG[posting.status as keyof typeof STATUS_CONFIG] || STATUS_CONFIG.draft;
  const Icon = cfg.icon;

  return (
    <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 hover:border-indigo-300 dark:hover:border-indigo-800/80 rounded-2xl p-5 shadow-xs transition-all flex flex-col justify-between group">
      <div>
        {/* Top bar: Status badge & dropdown menu */}
        <div className="flex items-center justify-between mb-3">
          <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${cfg.cls}`}>
            <Icon size={11} />
            {cfg.label}
          </span>

          <div className="relative">
            <button
              onClick={() => setMenuOpen(!menuOpen)}
              className="p-1 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors cursor-pointer"
            >
              <MoreVertical size={14} />
            </button>

            {menuOpen && (
              <div
                className="absolute right-0 top-7 w-36 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl shadow-lg z-20 py-1 overflow-hidden text-xs"
                onMouseLeave={() => setMenuOpen(false)}
              >
                <button
                  onClick={() => { setMenuOpen(false); onView(); }}
                  className="flex items-center gap-2 px-3.5 py-2 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 w-full text-left font-medium cursor-pointer"
                >
                  <Eye size={13} /> Rincian
                </button>
                <button
                  onClick={() => { setMenuOpen(false); onEdit(); }}
                  className="flex items-center gap-2 px-3.5 py-2 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 w-full text-left font-medium cursor-pointer"
                >
                  <Edit3 size={13} /> Edit
                </button>
                {posting.status !== 'open' && (
                  <button
                    onClick={() => { setMenuOpen(false); onPublish(); }}
                    className="flex items-center gap-2 px-3.5 py-2 text-emerald-600 dark:text-emerald-400 hover:bg-slate-50 dark:hover:bg-slate-700 w-full text-left font-medium cursor-pointer"
                  >
                    <Globe size={13} /> Publikasikan
                  </button>
                )}
                {posting.status === 'open' && (
                  <button
                    onClick={() => { setMenuOpen(false); onClose(); }}
                    className="flex items-center gap-2 px-3.5 py-2 text-amber-600 dark:text-amber-400 hover:bg-slate-50 dark:hover:bg-slate-700 w-full text-left font-medium cursor-pointer"
                  >
                    <Lock size={13} /> Tutup
                  </button>
                )}
                {posting.status === 'draft' && (
                  <button
                    onClick={() => { setMenuOpen(false); onDelete(); }}
                    className="flex items-center gap-2 px-3.5 py-2 text-rose-600 dark:text-rose-400 hover:bg-slate-50 dark:hover:bg-slate-700 w-full text-left font-medium cursor-pointer"
                  >
                    <Trash2 size={13} /> Hapus
                  </button>
                )}
              </div>
            )}
          </div>
        </div>

        <h3
          onClick={onView}
          className="font-bold text-slate-900 dark:text-white text-base leading-snug line-clamp-1 cursor-pointer hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors mb-1"
        >
          {posting.title}
        </h3>
        <p className="text-xs text-slate-500 dark:text-slate-400 mb-3 flex items-center gap-1.5 font-medium">
          <Building2 size={12} className="text-slate-400" />
          {posting.department || 'Umum'}
        </p>

        <div className="flex flex-wrap gap-1.5 text-[11px] text-slate-500 dark:text-slate-400">
          <span className="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700/60 font-medium">
            {EMP_TYPE_LABELS[posting.employment_type]}
          </span>
          {posting.location && (
            <span className="px-2 py-0.5 rounded-md bg-slate-100 dark:bg-slate-800 border border-slate-200 dark:border-slate-700/60 flex items-center gap-1 font-medium">
              <MapPin size={10} /> {posting.location}
            </span>
          )}
        </div>

        {/* Requirements Preview */}
        {posting.requirements && (
          <div className="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-800 space-y-1.5">
            <div className="flex items-center justify-between">
              <span className="text-[10px] uppercase font-bold text-indigo-600 dark:text-indigo-400 tracking-wider flex items-center gap-1">
                <CheckSquare size={10} /> Kualifikasi
              </span>
              <span className="text-[10px] text-slate-400">
                {posting.requirements.split('\n').filter((l: string) => l.trim().length > 0).length} syarat
              </span>
            </div>
            <div className="space-y-1">
              {posting.requirements.split('\n')
                .map((l: string) => l.replace(/^[\s•\-\*\d\.\)\-]+/, '').trim())
                .filter((l: string) => l.length > 0)
                .slice(0, 2)
                .map((req: string, idx: number) => (
                  <div key={idx} className="flex items-start gap-1.5 text-xs text-slate-600 dark:text-slate-300">
                    <Check size={11} className="text-emerald-500 mt-0.5 shrink-0" />
                    <span className="line-clamp-1">{req}</span>
                  </div>
                ))}
            </div>
          </div>
        )}
      </div>

      <div className="pt-3 border-t border-slate-100 dark:border-slate-800 flex items-center justify-between mt-4">
        <button
          onClick={onViewApplicants}
          className="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 font-semibold transition-colors cursor-pointer"
        >
          <Users size={14} />
          <span>
            {posting.applications_count ?? 0}
            {posting.max_applicants ? ` / ${posting.max_applicants}` : ''} Pelamar
          </span>
          {posting.max_applicants && (posting.applications_count ?? 0) >= posting.max_applicants && (
            <span className="px-1.5 py-0.5 rounded text-[10px] bg-rose-50 dark:bg-rose-950/80 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800 font-bold">
              Penuh
            </span>
          )}
        </button>

        <button
          onClick={onView}
          className="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1 cursor-pointer"
        >
          Detail &rarr;
        </button>
      </div>
    </div>
  );
}

// 3. ApplicationsListView
function ApplicationsListView({
  filterPosting, applications, meta, summary, loading, statusFilter,
  searchQuery, currentPage, onClearPostingFilter, onStatusFilter, onSearch,
  onPageChange, onViewDetail, onUpdateStatus, onDownloadResume, onDeleteApplication,
  onOpenInterviewModal, onOpenRejectionModal, onOpenOfferingModal
}: any) {
  const [localSearch, setLocalSearch] = useState(searchQuery);
  const searchTimer = useRef<ReturnType<typeof setTimeout>>();

  const handleSearchChange = (val: string) => {
    setLocalSearch(val);
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => onSearch(val), 400);
  };

  return (
    <div className="space-y-6">
      {/* Active Filter Banner (if filtering by a single posting) */}
      {filterPosting && (
        <div className="p-4 rounded-2xl bg-indigo-50/70 dark:bg-indigo-950/30 border border-indigo-200 dark:border-indigo-800/60 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3">
            <div className="p-2 bg-indigo-600/15 rounded-xl text-indigo-600 dark:text-indigo-400">
              <Briefcase size={18} />
            </div>
            <div>
              <p className="text-xs text-indigo-700 dark:text-indigo-300 font-medium">Menampilkan pelamar untuk posisi:</p>
              <h3 className="text-sm font-bold text-slate-900 dark:text-white">{filterPosting.title}</h3>
            </div>
          </div>
          <button
            onClick={onClearPostingFilter}
            className="px-3 py-1.5 rounded-xl bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold transition-all border border-slate-200 dark:border-slate-700 cursor-pointer"
          >
            Tampilkan Semua Posisi
          </button>
        </div>
      )}

      {/* Main Container */}
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-5 shadow-xs space-y-5">
        {/* Filter & Search Bar */}
        <div className="flex flex-col sm:flex-row gap-3">
          <div className="relative flex-1">
            <Search size={15} className="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400" />
            <input
              value={localSearch}
              onChange={e => handleSearchChange(e.target.value)}
              placeholder="Cari nama pelamar, email, atau no. telepon..."
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl pl-10 pr-4 py-2.5 text-xs sm:text-sm text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
            />
          </div>

          <div className="flex flex-wrap gap-1.5">
            {[
              { key: '', label: 'Semua' },
              { key: 'new', label: 'Baru' },
              { key: 'reviewed', label: 'Ditinjau' },
              { key: 'shortlisted', label: 'Shortlist' },
              { key: 'hired', label: 'Diterima' },
              { key: 'rejected', label: 'Ditolak' },
            ].map(f => (
              <button
                key={f.key}
                onClick={() => onStatusFilter(f.key)}
                className={`px-3 py-2 rounded-xl text-xs font-semibold transition-all border cursor-pointer ${
                  statusFilter === f.key
                    ? 'bg-indigo-600 text-white border-indigo-500 shadow-sm'
                    : 'bg-white dark:bg-slate-900 text-slate-600 dark:text-slate-400 border-slate-200 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800'
                }`}
              >
                {f.label}
              </button>
            ))}
          </div>
        </div>

        {/* Table */}
        {loading ? (
          <div className="flex flex-col items-center justify-center py-20 gap-3">
            <Loader2 className="animate-spin text-indigo-600" size={32} />
            <span className="text-xs text-slate-400">Memuat berkas pelamar...</span>
          </div>
        ) : applications.length === 0 ? (
          <div className="text-center py-16 bg-slate-50/50 dark:bg-slate-950/40 border border-slate-100 dark:border-slate-800/80 rounded-3xl p-6">
            <Users size={48} className="mx-auto text-slate-400 mb-3" />
            <h3 className="text-base font-bold text-slate-800 dark:text-white mb-1">Belum Ada Pelamar</h3>
            <p className="text-xs text-slate-400 max-w-sm mx-auto">
              Pelamar yang mengirimkan lamaran dari portal karir publik akan otomatis muncul di sini.
            </p>
          </div>
        ) : (
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden shadow-xs">
            <div className="overflow-x-auto">
              <table className="w-full text-xs sm:text-sm">
                <thead>
                  <tr className="border-b border-slate-100 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-800/50">
                    <th className="text-left px-5 py-3.5 text-slate-500 dark:text-slate-400 font-semibold text-[11px] uppercase tracking-wider">Pelamar</th>
                    <th className="text-left px-5 py-3.5 text-slate-500 dark:text-slate-400 font-semibold text-[11px] uppercase tracking-wider">Posisi Lowongan</th>
                    <th className="text-left px-5 py-3.5 text-slate-500 dark:text-slate-400 font-semibold text-[11px] uppercase tracking-wider">Pendidikan &amp; Notice</th>
                    <th className="text-left px-5 py-3.5 text-slate-500 dark:text-slate-400 font-semibold text-[11px] uppercase tracking-wider">Status Seleksi</th>
                    <th className="text-left px-5 py-3.5 text-slate-500 dark:text-slate-400 font-semibold text-[11px] uppercase tracking-wider">Tanggal Masuk</th>
                    <th className="text-right px-5 py-3.5 text-slate-500 dark:text-slate-400 font-semibold text-[11px] uppercase tracking-wider">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                  {applications.map((app: JobApplication) => {
                    const stCfg = APP_STATUS_CONFIG[app.status] || APP_STATUS_CONFIG.new;
                    return (
                      <tr key={app.id} className="hover:bg-slate-50/60 dark:hover:bg-slate-800/30 transition-colors">
                        <td className="px-5 py-4">
                          <div className="flex items-center gap-3">
                            <div className="w-9 h-9 rounded-xl bg-indigo-50 dark:bg-indigo-950 text-indigo-600 dark:text-indigo-400 border border-indigo-100 dark:border-indigo-800 flex items-center justify-center font-bold text-xs shrink-0">
                              {app.full_name.charAt(0).toUpperCase()}
                            </div>
                            <div>
                              <button
                                onClick={() => onViewDetail(app)}
                                className="font-bold text-slate-900 dark:text-white hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors text-left cursor-pointer"
                              >
                                {app.full_name}
                              </button>
                              <p className="text-[11px] text-slate-400">{app.email}</p>
                            </div>
                          </div>
                        </td>

                        <td className="px-5 py-4 text-slate-700 dark:text-slate-300">
                          <p className="font-medium text-slate-900 dark:text-white">{app.job_posting?.title || '-'}</p>
                          <p className="text-[11px] text-slate-400">{app.job_posting?.department || 'Umum'}</p>
                        </td>

                        <td className="px-5 py-4 text-slate-700 dark:text-slate-300">
                          <p className="font-medium text-slate-900 dark:text-white">{app.education || '-'} {app.institution_name ? `• ${app.institution_name}` : ''}</p>
                          <p className="text-[11px] text-slate-400">Notice: {app.notice_period || 'Langsung'}</p>
                        </td>

                        <td className="px-5 py-4">
                          <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border ${stCfg.cls}`}>
                            {stCfg.label}
                          </span>
                        </td>

                        <td className="px-5 py-4 text-slate-400 text-xs">
                          {formatDate(app.created_at)}
                        </td>

                        <td className="px-5 py-4">
                          <div className="flex items-center justify-end gap-1.5">
                            <button
                              onClick={() => onViewDetail(app)}
                              className="p-2 rounded-xl hover:bg-slate-100 dark:hover:bg-slate-800 text-slate-500 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition-colors cursor-pointer"
                              title="Rincian Berkas Pelamar"
                            >
                              <Eye size={15} />
                            </button>
                            {app.status === 'shortlisted' && (
                              <button
                                onClick={() => onOpenInterviewModal(app)}
                                className="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 transition-colors border border-indigo-200 dark:border-indigo-800 cursor-pointer"
                                title="Kirim Undangan Interview (Email)"
                              >
                                <Mail size={15} />
                              </button>
                            )}
                            {(app.status === 'shortlisted' || app.status === 'hired') && (
                              <button
                                onClick={() => onOpenOfferingModal(app)}
                                className="p-2 rounded-xl bg-emerald-50 dark:bg-emerald-950 hover:bg-emerald-100 dark:hover:bg-emerald-900/50 text-emerald-600 dark:text-emerald-400 transition-colors border border-emerald-200 dark:border-emerald-800 cursor-pointer"
                                title="Buat Surat Penawaran Kerja (Offering Letter)"
                              >
                                <FileCheck size={15} />
                              </button>
                            )}
                            {app.status === 'rejected' && (
                              <>
                                <button
                                  onClick={() => onOpenRejectionModal(app)}
                                  className="p-2 rounded-xl bg-amber-50 dark:bg-amber-950 hover:bg-amber-100 dark:hover:bg-amber-900/50 text-amber-600 dark:text-amber-400 transition-colors border border-amber-200 dark:border-amber-800 cursor-pointer"
                                  title="Kirim Email Penolakan Sopan"
                                >
                                  <Mail size={15} />
                                </button>
                                <button
                                  onClick={() => onDeleteApplication(app)}
                                  className="p-2 rounded-xl hover:bg-rose-50 dark:hover:bg-rose-950/60 text-rose-600 dark:text-rose-400 hover:text-rose-700 border border-rose-200 dark:border-rose-800/60 transition-colors cursor-pointer"
                                  title="Hapus Data Pelamar yang Ditolak"
                                >
                                  <Trash2 size={15} />
                                </button>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-center gap-2 pt-4 border-t border-slate-100 dark:border-slate-800">
            <button
              onClick={() => onPageChange(currentPage - 1)}
              disabled={currentPage <= 1}
              className="px-3.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 disabled:opacity-40 text-xs border border-slate-200 dark:border-slate-700 cursor-pointer"
            >
              &larr; Prev
            </button>
            <span className="text-xs text-slate-500 px-2 font-medium">
              Halaman {currentPage} dari {meta.last_page}
            </span>
            <button
              onClick={() => onPageChange(currentPage + 1)}
              disabled={currentPage >= meta.last_page}
              className="px-3.5 py-1.5 rounded-xl bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-50 disabled:opacity-40 text-xs border border-slate-200 dark:border-slate-700 cursor-pointer"
            >
              Next &rarr;
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

// 4. PostingFormView
function PostingFormView({ mode, data, errors, loading, onBack, onChange, onSubmit }: any) {
  return (
    <div className="max-w-4xl mx-auto space-y-6">
      <div className="flex items-center gap-3">
        <button
          onClick={onBack}
          className="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-all border border-slate-200 dark:border-slate-700 cursor-pointer"
        >
          <ChevronLeft size={18} />
        </button>
        <div>
          <h2 className="text-xl font-bold text-slate-900 dark:text-white tracking-tight">
            {mode === 'create' ? 'Buat Lowongan Pekerjaan Baru' : 'Edit Lowongan Pekerjaan'}
          </h2>
          <p className="text-xs text-slate-400 mt-0.5">Isi rincian posisi dan persyaratan kualifikasi.</p>
        </div>
      </div>

      <form onSubmit={onSubmit} className="space-y-5">
        {/* Basic info */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-7 space-y-4 shadow-xs">
          <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">1. Informasi Posisi</h3>

          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
              Judul Posisi <span className="text-rose-500">*</span>
            </label>
            <input
              value={data.title ?? ''}
              onChange={e => onChange('title', e.target.value)}
              required
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
              placeholder="Contoh: Senior Frontend Developer"
            />
            {errors.title && <p className="text-rose-500 text-xs mt-1">{errors.title}</p>}
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 flex items-center justify-between">
              <span className="flex items-center gap-1.5">
                <Mail size={13} className="text-indigo-600 dark:text-indigo-400" />
                Email Kontak HRD / Pengirim Undangan
              </span>
              <span className="text-[11px] text-slate-400 font-normal">(Default Email Akun)</span>
            </label>
            <input
              type="email"
              value={data.contact_email ?? ''}
              onChange={e => onChange('contact_email', e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
              placeholder="Contoh: hr.recruitment@perusahaan.com"
            />
            <p className="text-[11px] text-slate-400 mt-1">
              Email ini akan digunakan sebagai alamat pengirim saat HRD mengundang kandidat untuk wawancara kerja.
            </p>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Departemen</label>
              <input
                value={data.department ?? ''}
                onChange={e => onChange('department', e.target.value)}
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                placeholder="Contoh: Engineering / IT"
              />
            </div>
            <div>
              <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Lokasi Kerja</label>
              <input
                value={data.location ?? ''}
                onChange={e => onChange('location', e.target.value)}
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                placeholder="Contoh: Jakarta Selatan / Hybrid"
              />
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
                Tipe Pekerjaan <span className="text-rose-500">*</span>
              </label>
              <select
                value={data.employment_type ?? 'full_time'}
                onChange={e => onChange('employment_type', e.target.value)}
                required
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
              >
                <option value="full_time">Full Time</option>
                <option value="part_time">Part Time</option>
                <option value="contract">Kontrak</option>
                <option value="internship">Magang</option>
              </select>
            </div>

            <div>
              <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Batas Akhir (Deadline)</label>
              <CustomDatePicker
                value={data.deadline ?? ''}
                onChange={val => onChange('deadline', val || null)}
                placeholder="Pilih batas akhir lowongan"
              />
            </div>


            <div>
              <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5 flex items-center justify-between">
                <span>Maks. Pelamar (Kuota)</span>
                <span className="text-[10px] text-slate-400 font-normal">Opsional</span>
              </label>
              <input
                type="number"
                min={1}
                value={data.max_applicants ?? ''}
                onChange={e => onChange('max_applicants', e.target.value ? parseInt(e.target.value) : null)}
                placeholder="Tak Terbatas (Null)"
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
              />
            </div>
          </div>
          <p className="text-[11px] text-slate-400">
            💡 <strong>Batas Kuota Otomatis:</strong> Jika angka kuota diisi (misal: 50), lowongan akan <strong>secara otomatis ditutup</strong> saat jumlah pelamar yang masuk mencapai kuota tersebut. Biarkan kosong jika tidak ingin membatasi jumlah pelamar.
          </p>
        </div>

        {/* Description & Requirements */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-7 space-y-4 shadow-xs">
          <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">2. Uraian &amp; Kualifikasi</h3>

          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
              Deskripsi Pekerjaan <span className="text-rose-500">*</span>
            </label>
            <textarea
              value={data.description ?? ''}
              onChange={e => onChange('description', e.target.value)}
              required
              rows={5}
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-y"
              placeholder="Jelaskan gambaran tanggung jawab, peran, serta tugas sehari-hari..."
            />
            {errors.description && <p className="text-rose-500 text-xs mt-1">{errors.description}</p>}
          </div>

          <div>
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">
              Persyaratan &amp; Kualifikasi
            </label>
            <textarea
              value={data.requirements ?? ''}
              onChange={e => onChange('requirements', e.target.value)}
              rows={4}
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-y"
              placeholder="Tuliskan tiap persyaratan di baris baru (Contoh: Minimal S1 Teknik Informatika, Menguasai React / Flutter)..."
            />
          </div>
        </div>

        {/* Salary & Publication Status */}
        <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-7 space-y-4 shadow-xs">
          <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">3. Gaji &amp; Status Publikasi</h3>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Gaji Minimum (IDR)</label>
              <input
                type="number"
                min={0}
                value={data.salary_min ?? ''}
                onChange={e => onChange('salary_min', e.target.value ? parseInt(e.target.value) : null)}
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                placeholder="Contoh: 5000000"
              />
            </div>
            <div>
              <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Gaji Maksimum (IDR)</label>
              <input
                type="number"
                min={0}
                value={data.salary_max ?? ''}
                onChange={e => onChange('salary_max', e.target.value ? parseInt(e.target.value) : null)}
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500"
                placeholder="Contoh: 10000000"
              />
              {errors.salary_max && <p className="text-rose-500 text-xs mt-1">{errors.salary_max}</p>}
            </div>
          </div>

          <label className="flex items-center gap-3 cursor-pointer select-none pt-1">
            <input
              type="checkbox"
              checked={!!data.show_salary}
              onChange={e => onChange('show_salary', e.target.checked)}
              className="w-4 h-4 rounded text-indigo-600 bg-slate-50 dark:bg-slate-950 border-slate-300 dark:border-slate-700 focus:ring-indigo-500"
            />
            <span className="text-xs text-slate-700 dark:text-slate-300 font-medium">
              Tampilkan kisaran gaji di halaman portal karir publik
            </span>
          </label>

          <div className="pt-2">
            <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Status Lowongan</label>
            <select
              value={data.status ?? 'draft'}
              onChange={e => onChange('status', e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-4 py-2.5 text-slate-900 dark:text-white text-xs sm:text-sm focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 font-semibold"
            >
              <option value="draft">Draft (Disimpan saja, belum tayang ke publik)</option>
              <option value="open">Buka (Langsung tayang di portal karir publik)</option>
              <option value="closed">Tutup (Tidak menerima pelamar)</option>
            </select>
          </div>
        </div>

        {/* Form Actions */}
        <div className="flex items-center justify-end gap-3 pt-2">
          <button
            type="button"
            onClick={onBack}
            className="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold transition-all border border-slate-200 dark:border-slate-700 cursor-pointer"
          >
            Batal
          </button>

          <button
            type="submit"
            disabled={loading}
            className="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-xs font-bold transition-all shadow-sm shadow-indigo-500/20 flex items-center gap-2 cursor-pointer"
          >
            {loading && <Loader2 size={14} className="animate-spin" />}
            <span>{mode === 'create' ? 'Simpan & Buat Lowongan' : 'Simpan Perubahan'}</span>
          </button>
        </div>
      </form>
    </div>
  );
}

// 5. PostingDetailView
function PostingDetailView({ posting, onBack, onEdit, onPublish, onClose, onDelete, onViewApplicants }: any) {
  const cfg = STATUS_CONFIG[posting.status as keyof typeof STATUS_CONFIG] || STATUS_CONFIG.draft;
  const Icon = cfg.icon;

  return (
    <div className="max-w-5xl mx-auto space-y-6">
      {/* Top Header Bar */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <button
            onClick={onBack}
            className="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-all border border-slate-200 dark:border-slate-700 cursor-pointer"
          >
            <ChevronLeft size={18} />
          </button>
          <div>
            <h2 className="text-xl font-bold text-slate-900 dark:text-white tracking-tight">{posting.title}</h2>
            <p className="text-xs text-slate-400">
              Dibuat oleh <strong className="text-slate-700 dark:text-slate-200">{posting.creator?.name || 'HRD'}</strong> pada {formatDate(posting.created_at)}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={onEdit}
            className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-xs font-semibold border border-slate-200 dark:border-slate-700 transition-all cursor-pointer"
          >
            <Edit3 size={14} /> Edit
          </button>

          {posting.status !== 'open' && (
            <button
              onClick={onPublish}
              className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-all shadow-sm shadow-emerald-600/20 cursor-pointer"
            >
              <Globe size={14} /> Publikasikan
            </button>
          )}

          {posting.status === 'open' && (
            <button
              onClick={onClose}
              className="flex items-center gap-1.5 px-3.5 py-2 rounded-xl bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold transition-all shadow-sm shadow-amber-600/20 cursor-pointer"
            >
              <Lock size={14} /> Tutup
            </button>
          )}

          {posting.status === 'draft' && (
            <button
              onClick={onDelete}
              className="p-2 rounded-xl bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-900/60 text-rose-600 dark:text-rose-300 border border-rose-200 dark:border-rose-800/50 transition-all cursor-pointer"
              title="Hapus Lowongan"
            >
              <Trash2 size={15} />
            </button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-5">
          {/* Description */}
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-7 space-y-3 shadow-xs">
            <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">Deskripsi Pekerjaan</h3>
            <div className="text-xs sm:text-sm text-slate-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">
              {posting.description}
            </div>
          </div>

          {/* Requirements */}
          {posting.requirements && (
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-7 space-y-3 shadow-xs">
              <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">Kualifikasi &amp; Persyaratan</h3>
              <div className="space-y-2">
                {posting.requirements.split('\n').filter((l: string) => l.trim().length > 0).map((req: string, idx: number) => (
                  <div key={idx} className="flex items-start gap-2 text-xs sm:text-sm text-slate-700 dark:text-slate-300">
                    <Check size={14} className="text-emerald-500 mt-0.5 shrink-0" />
                    <span>{req.replace(/^[\s•\-\*\d\.\)\-]+/, '')}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Right Sidebar */}
        <div className="space-y-4">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 space-y-4 shadow-xs">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
              <span className="text-xs text-slate-400 font-medium">Status Publikasi</span>
              <span className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold border ${cfg.cls}`}>
                <Icon size={12} /> {cfg.label}
              </span>
            </div>

            <div className="space-y-2.5 text-xs text-slate-600 dark:text-slate-300">
              {posting.department && (
                <div className="flex items-center gap-2">
                  <Building2 size={14} className="text-slate-400 shrink-0" />
                  <span>Departemen: <strong className="text-slate-900 dark:text-white">{posting.department}</strong></span>
                </div>
              )}
              {posting.location && (
                <div className="flex items-center gap-2">
                  <MapPin size={14} className="text-slate-400 shrink-0" />
                  <span>Lokasi: <strong className="text-slate-900 dark:text-white">{posting.location}</strong></span>
                </div>
              )}
              <div className="flex items-center gap-2">
                <Briefcase size={14} className="text-slate-400 shrink-0" />
                <span>Tipe: <strong className="text-slate-900 dark:text-white">{EMP_TYPE_LABELS[posting.employment_type]}</strong></span>
              </div>
              {posting.deadline && (
                <div className="flex items-center gap-2">
                  <Calendar size={14} className="text-slate-400 shrink-0" />
                  <span>Batas: <strong className="text-slate-900 dark:text-white">{formatDate(posting.deadline)}</strong></span>
                </div>
              )}
              <div className="flex items-center gap-2">
                <Users size={14} className="text-slate-400 shrink-0" />
                <span>
                  Batas Kuota:{' '}
                  <strong className="text-slate-900 dark:text-white">
                    {posting.max_applicants ? `${posting.max_applicants} Pelamar (Maksimal)` : 'Tidak Terbatas (Unlimited)'}
                  </strong>
                </span>
              </div>
              {posting.show_salary && (posting.salary_min || posting.salary_max) && (
                <div className="flex items-center gap-2 text-emerald-600 dark:text-emerald-400 font-semibold">
                  <DollarSign size={14} className="shrink-0" />
                  <span>{formatRupiah(posting.salary_min)} - {formatRupiah(posting.salary_max)}</span>
                </div>
              )}
            </div>
          </div>

          {/* Quick Applicant CTA */}
          <button
            onClick={onViewApplicants}
            className="w-full p-5 rounded-3xl bg-indigo-50/80 dark:bg-indigo-950/30 hover:bg-indigo-100/90 dark:hover:bg-indigo-900/40 border border-indigo-200 dark:border-indigo-800/60 flex items-center justify-between transition-all text-left group shadow-xs cursor-pointer"
          >
            <div className="flex items-center gap-3">
              <div className="p-3 bg-indigo-600 rounded-2xl text-white shadow-sm shadow-indigo-600/30">
                <Users size={20} />
              </div>
              <div>
                <p className="text-xl font-black text-slate-900 dark:text-white">{posting.applications_count ?? 0}</p>
                <p className="text-xs text-indigo-600 dark:text-indigo-300 font-medium">Pelamar Terdaftar</p>
              </div>
            </div>
            <ArrowRight size={18} className="text-indigo-600 dark:text-indigo-400 group-hover:translate-x-1 transition-transform" />
          </button>
        </div>
      </div>
    </div>
  );
}

// 6. ApplicantDetailView
function ApplicantDetailView({
  application, onBack, onUpdateStatus, onDownloadResume, onDeleteApplication,
  onOpenInterviewModal, onOpenRejectionModal, onOpenOfferingModal
}: any) {
  const [notes, setNotes]     = useState(application.notes ?? '');
  const [saving, setSaving]   = useState(false);
  const [isDirty, setIsDirty] = useState(false);

  const stCfg = APP_STATUS_CONFIG[application.status as keyof typeof APP_STATUS_CONFIG] || APP_STATUS_CONFIG.new;

  const handleSaveNotes = async () => {
    setSaving(true);
    await onUpdateStatus(application.status, notes);
    setSaving(false);
    setIsDirty(false);
  };

  return (
    <div className="max-w-6xl mx-auto space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div className="flex items-center gap-3">
          <button
            onClick={onBack}
            className="p-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 transition-all border border-slate-200 dark:border-slate-700 cursor-pointer shrink-0"
          >
            <ChevronLeft size={18} />
          </button>
          <div className="min-w-0">
            <h2 className="text-xl font-bold text-slate-900 dark:text-white truncate">{application.full_name}</h2>
            <p className="text-xs text-slate-400">
              Melamar untuk posisi <strong className="text-indigo-600 dark:text-indigo-400">{application.job_posting?.title || 'Posisi Lowongan'}</strong>
            </p>
          </div>
        </div>

        {/* Header Document Action */}
        {application.has_resume ? (
          <button
            onClick={onDownloadResume}
            className="flex items-center gap-2 px-4 py-2 bg-indigo-50 hover:bg-indigo-100 dark:bg-indigo-950/60 dark:hover:bg-indigo-900/80 text-indigo-700 dark:text-indigo-300 border border-indigo-200/80 dark:border-indigo-800 rounded-xl text-xs font-bold transition-all shadow-xs cursor-pointer group shrink-0"
            title="Buka Berkas CV di Tab Baru"
          >
            <FileText size={15} className="text-indigo-600 dark:text-indigo-400 group-hover:scale-110 transition-transform" />
            <span>Buka Berkas CV</span>
            <ExternalLink size={13} className="text-indigo-400 dark:text-indigo-500" />
          </button>
        ) : (
          <div className="text-xs text-slate-400 italic bg-slate-100 dark:bg-slate-800/60 px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-800 shrink-0">
            Tidak ada lampiran CV
          </div>
        )}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        {/* Left column: profile info (7 of 12) */}
        <div className="lg:col-span-7 space-y-5">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-7 space-y-4 shadow-xs">
            <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">Biodata Pelamar</h3>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3.5 text-xs">
              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800">
                <span className="text-slate-400 block mb-1">Nama Lengkap &amp; Gender</span>
                <p className="font-semibold text-slate-900 dark:text-white text-sm">
                  {application.full_name}
                  {application.gender ? <span className="text-indigo-600 dark:text-indigo-400 font-normal ml-1.5">({application.gender})</span> : ''}
                </p>
              </div>

              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800">
                <span className="text-slate-400 block mb-1">Tempat, Tanggal Lahir</span>
                <p className="font-semibold text-slate-900 dark:text-white text-sm">
                  {application.birth_place ? `${application.birth_place}, ` : ''}
                  {application.birth_date ? formatDate(application.birth_date) : '-'}
                  {application.birth_date && (
                    <span className="text-slate-400 text-xs font-normal ml-1.5">
                      ({Math.floor((Date.now() - new Date(application.birth_date).getTime()) / (365.25 * 24 * 3600 * 1000))} thn)
                    </span>
                  )}
                </p>
              </div>

              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800">
                <span className="text-slate-400 block mb-1">Kewarganegaraan</span>
                <p className="font-semibold text-slate-900 dark:text-white text-sm">{application.nationality || 'WNI (Indonesia)'}</p>
              </div>

              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800">
                <span className="text-slate-400 block mb-1 flex items-center justify-between">
                  <span>Alamat Email</span>
                  <span className="text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">Email Aktif</span>
                </span>
                <p className="font-semibold text-slate-900 dark:text-white text-sm truncate">{application.email}</p>
              </div>

              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800">
                <span className="text-slate-400 block mb-1">No. WhatsApp / HP</span>
                <p className="font-semibold text-slate-900 dark:text-white text-sm">{application.phone || '-'}</p>
              </div>

              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800">
                <span className="text-slate-400 block mb-1">Pendidikan &amp; Sekolah</span>
                <p className="font-semibold text-slate-900 dark:text-white text-sm">
                  {application.education || '-'}
                  {application.institution_name ? ` • ${application.institution_name}` : ''}
                </p>
              </div>

              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800">
                <span className="text-slate-400 block mb-1">Pengalaman Kerja</span>
                <p className="font-semibold text-slate-900 dark:text-white text-sm">
                  {application.experience_years != null ? `${application.experience_years} Tahun` : 'Fresh Graduate'}
                </p>
              </div>

              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800">
                <span className="text-slate-400 block mb-1">Ketersediaan Mulai Bekerja (Notice Period)</span>
                <p className="font-bold text-indigo-600 dark:text-indigo-400 text-sm">
                  {application.notice_period || 'Langsung Bekerja (Immediate)'}
                </p>
              </div>

              {/* Alamat Lengkap Terstruktur */}
              <div className="p-3.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 sm:col-span-2 space-y-1">
                <div className="flex items-center justify-between">
                  <span className="text-slate-400 block font-medium">Alamat Domisili Lengkap</span>
                  {application.postal_code && (
                    <span className="px-2 py-0.5 rounded-md bg-indigo-50 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-300 font-mono font-bold text-[11px] border border-indigo-200 dark:border-indigo-800">
                      Kode Pos: {application.postal_code}
                    </span>
                  )}
                </div>
                <p className="font-semibold text-slate-900 dark:text-white text-sm leading-relaxed">
                  {application.address || '-'}
                </p>
                {(application.district || application.city || application.province || application.subdistrict) && (
                  <p className="text-slate-500 dark:text-slate-400 text-xs">
                    {[
                      application.subdistrict ? `Kel. ${application.subdistrict}` : '',
                      application.district ? `Kec. ${application.district}` : '',
                      application.city,
                      application.province
                    ].filter(Boolean).join(', ')}
                  </p>
                )}
              </div>
            </div>
          </div>

          {/* Cover Letter */}
          {application.cover_letter && (
            <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-7 space-y-3 shadow-xs">
              <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">Surat Lamaran / Cover Letter</h3>
              <p className="text-xs sm:text-sm text-slate-700 dark:text-slate-300 leading-relaxed whitespace-pre-wrap">
                {application.cover_letter}
              </p>
            </div>
          )}
        </div>

        {/* Right column: Action Pipeline & Notes (5 of 12) */}
        <div className="lg:col-span-5 space-y-4">
          <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl p-6 sm:p-7 space-y-5 shadow-xs">
            <div className="flex items-center justify-between pb-3 border-b border-slate-100 dark:border-slate-800">
              <h3 className="text-xs font-bold text-slate-400 uppercase tracking-wider">Alur Seleksi</h3>
              <span className={`px-3 py-1 rounded-full text-xs font-bold border ${stCfg.cls}`}>
                {stCfg.label}
              </span>
            </div>

            {/* Pipeline Stage Indicators */}
            <div className="grid grid-cols-4 gap-1.5 p-1.5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-[11px] font-bold text-center">
              <div className={`py-2 rounded-xl transition-all ${
                application.status === 'new' 
                  ? 'bg-blue-600 text-white shadow-xs font-bold' 
                  : 'text-slate-400'
              }`}>
                1. Baru
              </div>
              <div className={`py-2 rounded-xl transition-all ${
                application.status === 'reviewed' 
                  ? 'bg-amber-600 text-white shadow-xs font-bold' 
                  : 'text-slate-400'
              }`}>
                2. Ditinjau
              </div>
              <div className={`py-2 rounded-xl transition-all ${
                application.status === 'shortlisted'
                  ? 'bg-indigo-600 text-white shadow-xs font-bold' 
                  : 'text-slate-400'
              }`}>
                3. Shortlist
              </div>
              <div className={`py-2 rounded-xl transition-all ${
                application.status === 'hired'
                  ? 'bg-emerald-600 text-white shadow-xs font-bold'
                  : application.status === 'rejected'
                  ? 'bg-rose-600 text-white shadow-xs font-bold'
                  : 'text-slate-400'
              }`}>
                {application.status === 'hired' ? '4. Diterima' : application.status === 'rejected' ? '4. Ditolak' : '4. Final'}
              </div>
            </div>

            {/* Dynamic Action Box based on Status */}
            <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 space-y-3">
              {application.status === 'new' && (
                <>
                  <div className="flex items-start gap-2.5 text-xs text-blue-700 dark:text-blue-300">
                    <Clock size={15} className="text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
                    <p className="leading-relaxed">
                      Berkas lamaran baru masuk dan belum ditinjau oleh tim HRD.
                    </p>
                  </div>
                  {application.has_resume ? (
                    <button
                      onClick={onDownloadResume}
                      className="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm shadow-blue-600/20 flex items-center justify-center gap-2 cursor-pointer"
                    >
                      <FileText size={15} />
                      <span>Buka Berkas CV (Otomatis Ditinjau)</span>
                    </button>
                  ) : (
                    <button
                      onClick={() => onUpdateStatus('reviewed')}
                      className="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm shadow-amber-600/20 flex items-center justify-center gap-2 cursor-pointer"
                    >
                      <CheckCircle size={15} />
                      <span>Tandai Sudah Ditinjau</span>
                    </button>
                  )}
                  <div className="grid grid-cols-2 gap-2 pt-1">
                    <button
                      onClick={() => onUpdateStatus('shortlisted')}
                      className="py-2 bg-indigo-50 dark:bg-indigo-950/60 hover:bg-indigo-100 dark:hover:bg-indigo-900/60 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-800 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 cursor-pointer"
                    >
                      <Award size={13} />
                      <span>Shortlist</span>
                    </button>
                    <button
                      onClick={() => onUpdateStatus('rejected')}
                      className="py-2 bg-rose-50 dark:bg-rose-950/40 hover:bg-rose-100 dark:hover:bg-rose-900/60 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800/40 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 cursor-pointer"
                    >
                      <XCircle size={13} />
                      <span>Tolak</span>
                    </button>
                  </div>
                </>
              )}

              {application.status === 'reviewed' && (
                <>
                  <div className="flex items-start gap-2.5 text-xs text-amber-700 dark:text-amber-300">
                    <CheckCircle size={15} className="text-amber-600 dark:text-amber-400 shrink-0 mt-0.5" />
                    <p className="leading-relaxed">
                      Berkas CV telah ditinjau. Tentukan apakah kandidat memenuhi kualifikasi untuk masuk ke <strong>Shortlist</strong>.
                    </p>
                  </div>
                  <div className="space-y-2 pt-1">
                    <button
                      onClick={() => onUpdateStatus('shortlisted')}
                      className="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm shadow-indigo-600/20 flex items-center justify-center gap-2 cursor-pointer"
                    >
                      <Award size={15} />
                      <span>Loloskan ke Shortlist</span>
                    </button>
                    <button
                      onClick={() => onUpdateStatus('rejected')}
                      className="w-full py-2 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/40 dark:hover:bg-rose-900/60 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800/40 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer"
                    >
                      <XCircle size={14} />
                      <span>Tolak Pelamar Ini</span>
                    </button>
                  </div>
                </>
              )}

              {application.status === 'shortlisted' && (
                <>
                  <div className="flex items-start gap-2.5 text-xs text-indigo-700 dark:text-indigo-300">
                    <Award size={15} className="text-indigo-600 dark:text-indigo-400 shrink-0 mt-0.5" />
                    <p className="leading-relaxed">
                      Kandidat berada di tahap <strong>Shortlist</strong>. Silakan hubungi untuk wawancara atau terbitkan surat penawaran.
                    </p>
                  </div>
                  <div className="space-y-3 pt-1">
                    <div className="space-y-2">
                      <button
                        onClick={onOpenInterviewModal}
                        className="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm shadow-indigo-600/20 flex items-center justify-center gap-2 cursor-pointer"
                      >
                        <Mail size={15} />
                        <span>Undang Interview (Kirim Email)</span>
                      </button>
                      <button
                        onClick={onOpenOfferingModal}
                        className="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm shadow-emerald-600/20 flex items-center justify-center gap-2 cursor-pointer"
                      >
                        <FileCheck size={15} />
                        <span>Buat Offering Letter (Surat Kerja)</span>
                      </button>
                    </div>

                    <div className="pt-2 border-t border-slate-200/80 dark:border-slate-800">
                      <p className="text-[11px] font-semibold text-slate-500 dark:text-slate-400 mb-2">Keputusan Akhir:</p>
                      <div className="grid grid-cols-2 gap-2">
                        <button
                          onClick={() => onUpdateStatus('hired')}
                          className="py-2.5 bg-emerald-50 dark:bg-emerald-950/60 hover:bg-emerald-100 dark:hover:bg-emerald-900/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 cursor-pointer shadow-xs"
                        >
                          <Check size={14} />
                          <span>Terima Kerja</span>
                        </button>
                        <button
                          onClick={() => onUpdateStatus('rejected')}
                          className="py-2.5 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/50 dark:hover:bg-rose-900/60 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800/40 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 cursor-pointer"
                        >
                          <X size={14} />
                          <span>Tolak</span>
                        </button>
                      </div>
                    </div>
                  </div>
                </>
              )}

              {application.status === 'rejected' && (
                <>
                  <div className="flex items-start gap-2.5 text-xs text-rose-700 dark:text-rose-300">
                    <XCircle size={15} className="text-rose-600 dark:text-rose-400 shrink-0 mt-0.5" />
                    <p className="leading-relaxed">
                      Kandidat ini berstatus ditolak. Anda dapat mengirimkan email penolakan sopan atau menghapus data.
                    </p>
                  </div>
                  <div className="space-y-2 pt-1">
                    <button
                      onClick={onOpenRejectionModal}
                      className="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm shadow-amber-600/20 flex items-center justify-center gap-2 cursor-pointer"
                    >
                      <Mail size={15} />
                      <span>Kirim Email Penolakan Sopan</span>
                    </button>
                    <button
                      onClick={() => onDeleteApplication(application)}
                      className="w-full py-2.5 bg-rose-50 hover:bg-rose-100 dark:bg-rose-950/60 dark:hover:bg-rose-900/80 text-rose-700 dark:text-rose-300 border border-rose-200 dark:border-rose-800/60 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-2 cursor-pointer"
                    >
                      <Trash2 size={14} />
                      <span>Hapus Data Pelamar</span>
                    </button>
                    <button
                      onClick={() => onUpdateStatus('reviewed')}
                      className="w-full py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold transition-all border border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1.5 cursor-pointer"
                    >
                      <RefreshCw size={13} />
                      <span>Kembalikan ke Status Ditinjau</span>
                    </button>
                  </div>
                </>
              )}

              {application.status === 'hired' && (
                <div className="space-y-3">
                  <div className="flex items-start gap-2.5 text-xs text-emerald-700 dark:text-emerald-300">
                    <CheckCircle size={16} className="text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                    <div>
                      <p className="font-bold text-slate-900 dark:text-white text-xs sm:text-sm">Kandidat Resmi Diterima Bekerja 🎉</p>
                      <p className="text-[11px] text-emerald-600 dark:text-emerald-400/80 mt-0.5">
                        Proses seleksi telah selesai. Silakan terbitkan dokumen Offering Letter atau proses onboarding karyawan baru.
                      </p>
                    </div>
                  </div>
                  <button
                    onClick={onOpenOfferingModal}
                    className="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold transition-all shadow-sm shadow-emerald-600/20 flex items-center justify-center gap-2 cursor-pointer"
                  >
                    <FileCheck size={15} />
                    <span>Lihat / Buat Offering Letter</span>
                  </button>
                  <button
                    onClick={() => onUpdateStatus('shortlisted')}
                    className="w-full py-2 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 rounded-xl text-xs font-semibold transition-all border border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1.5 cursor-pointer"
                  >
                    <RefreshCw size={13} />
                    <span>Kembalikan ke Shortlist</span>
                  </button>
                </div>
              )}
            </div>

            {/* Notes Section */}
            <div>
              <label className="block text-xs font-semibold text-slate-700 dark:text-slate-300 mb-1.5">Catatan Internal HRD</label>
              <textarea
                value={notes}
                onChange={e => { setNotes(e.target.value); setIsDirty(true); }}
                rows={3}
                placeholder="Tuliskan hasil review berkas, jadwal interview, atau evaluasi..."
                className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3.5 py-2 text-xs text-slate-900 dark:text-white placeholder-slate-400 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 resize-none"
              />
              <button
                onClick={handleSaveNotes}
                disabled={saving || !isDirty}
                className="mt-2 w-full py-2 bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 disabled:opacity-40 text-slate-700 dark:text-slate-200 rounded-xl text-xs font-semibold transition-all border border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1.5 cursor-pointer"
              >
                {saving ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
                <span>Simpan Catatan</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

// 7. InterviewInvitationModal
interface InterviewModalProps {
  application: JobApplication;
  onClose: () => void;
  onSaveNotes: (notes: string) => Promise<void>;
  showToast: (msg: string, type?: 'success' | 'error') => void;
}

function InterviewInvitationModal({ application, onClose, onSaveNotes, showToast }: InterviewModalProps) {
  const currentUser = getStoredUser();
  const companyName = (application.job_posting as any)?.company?.name || 'PT Maju Bersama';
  const jobTitle = application.job_posting?.title || 'Posisi Lowongan';

  const defaultDate = new Date();
  defaultDate.setDate(defaultDate.getDate() + 1);
  const defaultDateStr = defaultDate.toISOString().split('T')[0];

  const [senderEmail, setSenderEmail] = useState(
    application.job_posting?.contact_email || currentUser?.email || 'recruitment@majubersama.com'
  );
  const [recipientEmail, setRecipientEmail] = useState(application.email);
  const [senderName, setSenderName] = useState(currentUser?.name || 'Tim Rekrutmen & HRD');
  const [interviewDate, setInterviewDate] = useState(defaultDateStr);
  const [interviewTime, setInterviewTime] = useState('10:00 WIB');
  const [interviewMode, setInterviewMode] = useState<'online' | 'onsite'>('online');
  const [interviewLocation, setInterviewLocation] = useState(
    'Google Meet / Zoom (Tautan ruang pertemuan akan dikirimkan via kalender)'
  );
  const [notes, setNotes] = useState('Harap siapkan ringkasan portofolio/CV dan berpakaian rapi.');
  const [copied, setCopied] = useState(false);
  const [saving, setSaving] = useState(false);

  const formattedDate = new Date(interviewDate).toLocaleDateString('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });

  const emailSubject = `Undangan Wawancara Kerja: ${jobTitle} — ${companyName}`;

  const emailBody = `Yth. Sdr/i ${application.full_name},

Terima kasih atas minat dan lamaran yang Anda kirimkan untuk posisi "${jobTitle}" di ${companyName}.

Berdasarkan hasil peninjauan berkas CV dan kualifikasi yang Anda lampirkan, kami mengundang Anda untuk mengikuti tahapan Wawancara Kerja (Interview) yang akan dilaksanakan pada:

📅 Hari / Tanggal : ${formattedDate}
⏰ Waktu         : ${interviewTime}
📍 Metode / Tempat: ${interviewMode === 'online' ? 'Online Video Meeting' : 'Tatap Muka di Kantor'} (${interviewLocation})
👤 Pewawancara   : ${senderName}

Catatan Tambahan:
${notes}

Mohon konfirmasi kesediaan Anda untuk menghadiri sesi wawancara ini dengan membalas email ini selambat-lambatnya 1 (satu) hari sebelum jadwal yang telah ditentukan.

Jika ada pertanyaan atau kendala terkait jadwal di atas, silakan hubungi kami melalui email ini (${senderEmail}).

Hormat kami,
${senderName}
${companyName}`;

  const handleCopy = () => {
    navigator.clipboard.writeText(emailBody);
    setCopied(true);
    showToast('Template email berhasil disalin ke clipboard!');
    setTimeout(() => setCopied(false), 2500);
  };

  const handleOpenGmail = () => {
    const gmailUrl = `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(
      recipientEmail
    )}&su=${encodeURIComponent(emailSubject)}&body=${encodeURIComponent(emailBody)}`;
    window.open(gmailUrl, '_blank', 'noopener,noreferrer');
  };

  const handleOpenMailto = () => {
    const mailtoUrl = `mailto:${encodeURIComponent(recipientEmail)}?subject=${encodeURIComponent(
      emailSubject
    )}&body=${encodeURIComponent(emailBody)}`;
    window.open(mailtoUrl, '_blank');
  };

  const handleSaveAndMarkInvited = async () => {
    setSaving(true);
    const updatedNote = `[JADWAL INTERVIEW]: ${formattedDate} pukul ${interviewTime} (${interviewMode === 'online' ? 'Online' : 'Onsite'}) - Pengirim: ${senderEmail}.\n${application.notes ? application.notes + '\n\n' : ''}`;
    try {
      await onSaveNotes(updatedNote);
      showToast('Jadwal interview berhasil dicatat ke data pelamar!');
      onClose();
    } catch (err: any) {
      showToast(err.message || 'Gagal menyimpan catatan.', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-md overflow-y-auto">
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl max-w-2xl w-full p-6 sm:p-7 space-y-5 shadow-2xl my-8">
        
        {/* Modal Header */}
        <div className="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-2xl bg-indigo-50 dark:bg-indigo-950/60 border border-indigo-100 dark:border-indigo-800 text-indigo-600 dark:text-indigo-400">
              <Mail size={20} />
            </div>
            <div>
              <h3 className="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Buat Undangan Wawancara (Interview)</h3>
              <p className="text-xs text-slate-400">Kirim email undangan kepada kandidat terpilih.</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors cursor-pointer"
          >
            <X size={16} />
          </button>
        </div>

        {/* Sender & Recipient Header Card */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 text-xs">
          <div>
            <span className="text-slate-500 dark:text-slate-400 block mb-1 font-medium flex items-center gap-1">
              <Send size={12} className="text-indigo-600 dark:text-indigo-400" /> Dari (Email HRD / Pengirim):
            </span>
            <input
              type="email"
              value={senderEmail}
              onChange={e => setSenderEmail(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white font-medium focus:outline-none focus:border-indigo-500 text-xs"
              placeholder="hrd@perusahaan.com"
            />
          </div>

          <div>
            <span className="text-slate-500 dark:text-slate-400 block mb-1 font-medium flex items-center gap-1">
              <User size={12} className="text-emerald-600 dark:text-emerald-400" /> Kepada (Email Pelamar):
            </span>
            <input
              type="email"
              value={recipientEmail}
              onChange={e => setRecipientEmail(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white font-medium focus:outline-none focus:border-indigo-500 text-xs"
            />
          </div>
        </div>

        {/* Schedule Inputs */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Tanggal Wawancara</label>
            <CustomDatePicker
              value={interviewDate}
              onChange={val => setInterviewDate(val)}
              placeholder="Pilih tanggal interview"
              size="sm"
            />
          </div>


          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Waktu / Jam Wawancara (24 Jam WIB)</label>
            <CustomTimePicker
              value={interviewTime}
              onChange={val => setInterviewTime(val)}
              placeholder="Pilih jam wawancara"
              size="sm"
              includeTimezone={true}
              defaultTimezone="WIB"
            />
          </div>

          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Metode Wawancara</label>
            <select
              value={interviewMode}
              onChange={e => {
                const val = e.target.value as 'online' | 'onsite';
                setInterviewMode(val);
                if (val === 'online') {
                  setInterviewLocation('Google Meet / Zoom (Tautan ruang pertemuan akan dikirimkan via kalender)');
                } else {
                  setInterviewLocation('Ruang Interview Lantai 2, Gedung Kantor Pusat');
                }
              }}
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
            >
              <option value="online">Online Video Meeting (Google Meet / Zoom)</option>
              <option value="onsite">Tatap Muka (Di Kantor)</option>
            </select>
          </div>

          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Lokasi / Tautan Meeting</label>
            <input
              type="text"
              value={interviewLocation}
              onChange={e => setInterviewLocation(e.target.value)}
              className="w-full bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 rounded-xl px-3 py-2 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
            />
          </div>
        </div>

        {/* Live Email Preview */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <label className="text-xs font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
              <Sparkles size={13} className="text-indigo-600 dark:text-indigo-400" />
              Preview Template Email Undangan
            </label>
            <button
              onClick={handleCopy}
              className="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 dark:hover:text-indigo-300 flex items-center gap-1 cursor-pointer"
            >
              {copied ? <Check size={13} className="text-emerald-500" /> : <Copy size={13} />}
              <span>{copied ? 'Tersalin!' : 'Salin Teks'}</span>
            </button>
          </div>
          <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-700 dark:text-slate-300 font-mono whitespace-pre-wrap max-h-48 overflow-y-auto leading-relaxed border-l-2 border-l-indigo-600">
            <div className="text-slate-900 dark:text-white font-bold pb-2 mb-2 border-b border-slate-200 dark:border-slate-800">
              Subjek: {emailSubject}
            </div>
            {emailBody}
          </div>
        </div>

        {/* Action Buttons */}
        <div className="space-y-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
          <button
            type="button"
            onClick={handleOpenGmail}
            className="w-full py-3 px-5 rounded-xl bg-gradient-to-r from-red-600 via-rose-600 to-red-600 hover:from-red-500 hover:to-rose-500 text-white font-bold text-xs sm:text-sm tracking-wide shadow-md shadow-red-500/20 transition-all flex items-center justify-center gap-2 cursor-pointer"
          >
            <Mail size={16} className="text-white" />
            <span>Buka di Gmail Web (Langsung Kirim)</span>
            <ExternalLink size={14} className="opacity-80" />
          </button>

          <div className="flex flex-col sm:flex-row items-center gap-2">
            <button
              type="button"
              onClick={handleOpenMailto}
              className="w-full sm:flex-1 py-2.5 px-4 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium text-xs transition-all border border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1.5 cursor-pointer"
            >
              <ExternalLink size={13} />
              <span>Aplikasi Email Desktop</span>
            </button>

            <button
              type="button"
              onClick={handleSaveAndMarkInvited}
              disabled={saving}
              className="w-full sm:w-auto py-2.5 px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs transition-all flex items-center justify-center gap-1.5 shadow-sm shadow-emerald-600/20 cursor-pointer"
            >
              {saving ? <Loader2 size={13} className="animate-spin" /> : <Check size={13} />}
              <span>Tandai Telah Diundang</span>
            </button>
          </div>
        </div>

      </div>
    </div>
  );
}

// 8. RejectionEmailModal (Fitur Nomor 2 Bagian E)
interface RejectionModalProps {
  application: JobApplication;
  onClose: () => void;
  onMarkRejected: () => Promise<void>;
  showToast: (msg: string, type?: 'success' | 'error') => void;
}

function RejectionEmailModal({ application, onClose, onMarkRejected, showToast }: RejectionModalProps) {
  const currentUser = getStoredUser();
  const companyName = (application.job_posting as any)?.company?.name || 'PT Maju Bersama';
  const jobTitle = application.job_posting?.title || 'Posisi Lowongan';

  const [senderEmail, setSenderEmail] = useState(
    application.job_posting?.contact_email || currentUser?.email || 'recruitment@majubersama.com'
  );
  const [recipientEmail, setRecipientEmail] = useState(application.email);
  const [senderName, setSenderName] = useState(currentUser?.name || 'Tim Rekrutmen & HRD');
  const [copied, setCopied] = useState(false);

  const emailSubject = `Pemberitahuan Hasil Seleksi Lamaran: ${jobTitle} — ${companyName}`;

  const emailBody = `Yth. Sdr/i ${application.full_name},

Terima kasih atas minat dan waktu yang telah Anda luangkan untuk melamar posisi "${jobTitle}" di ${companyName}.

Kami sangat mengapresiasi antusiasme dan profil kualifikasi yang Anda bagikan. Setelah melalui proses peninjauan berkas secara saksama, kami ingin menyampaikan bahwa untuk saat ini kami belum dapat melanjutkan proses lamaran Anda ke tahapan berikutnya, dikarenakan kualifikasi yang kami butuhkan saat ini lebih mendekati kandidat lainnya.

Data dan profil Anda akan tetap tersimpan dalam database kami, dan kami tidak menutup kemungkinan untuk menghubungi Anda kembali apabila terdapat peluang karir di masa mendatang yang sesuai dengan keahlian Anda.

Sekali lagi kami mengucapkan terima kasih atas partisipasi Anda, dan kami mendoakan kesuksesan dalam perjalanan karir Anda selanjutnya.

Hormat kami,
${senderName}
${companyName}`;

  const handleCopy = () => {
    navigator.clipboard.writeText(emailBody);
    setCopied(true);
    showToast('Template penolakan sopan berhasil disalin!');
    setTimeout(() => setCopied(false), 2500);
  };

  const handleOpenGmail = () => {
    const gmailUrl = `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(
      recipientEmail
    )}&su=${encodeURIComponent(emailSubject)}&body=${encodeURIComponent(emailBody)}`;
    window.open(gmailUrl, '_blank', 'noopener,noreferrer');
  };

  const handleOpenMailto = () => {
    const mailtoUrl = `mailto:${encodeURIComponent(recipientEmail)}?subject=${encodeURIComponent(
      emailSubject
    )}&body=${encodeURIComponent(emailBody)}`;
    window.open(mailtoUrl, '_blank');
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-md overflow-y-auto">
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl max-w-2xl w-full p-6 sm:p-7 space-y-5 shadow-2xl my-8">
        
        {/* Modal Header */}
        <div className="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-2xl bg-amber-50 dark:bg-amber-950/60 border border-amber-100 dark:border-amber-800 text-amber-600 dark:text-amber-400">
              <Mail size={20} />
            </div>
            <div>
              <h3 className="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Kirim Email Penolakan Sopan (Rejection Letter)</h3>
              <p className="text-xs text-slate-400">Pemberitahuan hasil seleksi yang santun dan menjaga citra baik perusahaan.</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors cursor-pointer"
          >
            <X size={16} />
          </button>
        </div>

        {/* Sender & Recipient Box */}
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 p-4 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 text-xs">
          <div>
            <span className="text-slate-500 dark:text-slate-400 block mb-1 font-medium flex items-center gap-1">
              <Send size={12} className="text-indigo-600 dark:text-indigo-400" /> Dari:
            </span>
            <input
              type="email"
              value={senderEmail}
              onChange={e => setSenderEmail(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white font-medium focus:outline-none focus:border-indigo-500 text-xs"
            />
          </div>
          <div>
            <span className="text-slate-500 dark:text-slate-400 block mb-1 font-medium flex items-center gap-1">
              <User size={12} className="text-amber-600 dark:text-amber-400" /> Kepada:
            </span>
            <input
              type="email"
              value={recipientEmail}
              onChange={e => setRecipientEmail(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white font-medium focus:outline-none focus:border-indigo-500 text-xs"
            />
          </div>
        </div>

        {/* Live Email Preview */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <label className="text-xs font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
              <Sparkles size={13} className="text-amber-600 dark:text-amber-400" />
              Preview Template Email Penolakan
            </label>
            <button
              onClick={handleCopy}
              className="text-xs font-semibold text-amber-600 dark:text-amber-400 hover:text-amber-700 dark:hover:text-amber-300 flex items-center gap-1 cursor-pointer"
            >
              {copied ? <Check size={13} className="text-emerald-500" /> : <Copy size={13} />}
              <span>{copied ? 'Tersalin!' : 'Salin Teks'}</span>
            </button>
          </div>
          <div className="p-4 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-700 dark:text-slate-300 font-mono whitespace-pre-wrap max-h-56 overflow-y-auto leading-relaxed border-l-2 border-l-amber-500">
            <div className="text-slate-900 dark:text-white font-bold pb-2 mb-2 border-b border-slate-200 dark:border-slate-800">
              Subjek: {emailSubject}
            </div>
            {emailBody}
          </div>
        </div>

        {/* Action Buttons */}
        <div className="space-y-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
          <button
            type="button"
            onClick={handleOpenGmail}
            className="w-full py-3 px-5 rounded-xl bg-gradient-to-r from-red-600 via-rose-600 to-red-600 hover:from-red-500 hover:to-rose-500 text-white font-bold text-xs sm:text-sm tracking-wide shadow-md shadow-red-500/20 transition-all flex items-center justify-center gap-2 cursor-pointer"
          >
            <Mail size={16} className="text-white" />
            <span>Buka di Gmail Web (Langsung Kirim)</span>
            <ExternalLink size={14} className="opacity-80" />
          </button>

          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={handleOpenMailto}
              className="flex-1 py-2.5 px-4 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 font-medium text-xs transition-all border border-slate-200 dark:border-slate-700 flex items-center justify-center gap-1.5 cursor-pointer"
            >
              <ExternalLink size={13} />
              <span>Aplikasi Email Desktop</span>
            </button>
            <button
              type="button"
              onClick={onClose}
              className="py-2.5 px-5 rounded-xl bg-slate-200 hover:bg-slate-300 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-800 dark:text-slate-200 font-semibold text-xs transition-all cursor-pointer"
            >
              Tutup
            </button>
          </div>
        </div>

      </div>
    </div>
  );
}

// 9. OfferingLetterModal (Fitur Nomor 3 Bagian F)
interface OfferingModalProps {
  application: JobApplication;
  onClose: () => void;
  onSaveOffering: (details: string) => Promise<void>;
  showToast: (msg: string, type?: 'success' | 'error') => void;
}

function OfferingLetterModal({ application, onClose, onSaveOffering, showToast }: OfferingModalProps) {
  const currentUser = getStoredUser();
  const companyName = (application.job_posting as any)?.company?.name || 'PT Maju Bersama';
  const jobTitle = application.job_posting?.title || 'Posisi Lowongan';

  // Join date default 14 days from now
  const defaultJoinDate = new Date();
  defaultJoinDate.setDate(defaultJoinDate.getDate() + 14);
  const defaultJoinDateStr = defaultJoinDate.toISOString().split('T')[0];

  // Expiry date default 7 days from now
  const defaultExpiryDate = new Date();
  defaultExpiryDate.setDate(defaultExpiryDate.getDate() + 7);
  const defaultExpiryDateStr = defaultExpiryDate.toISOString().split('T')[0];

  const [positionTitle, setPositionTitle] = useState(jobTitle);
  const [department, setDepartment] = useState(application.job_posting?.department || 'Operasional');
  const [joinDate, setJoinDate] = useState(defaultJoinDateStr);
  const [expiryDate, setExpiryDate] = useState(defaultExpiryDateStr);
  const [offeredSalary, setOfferedSalary] = useState<number | string>(
    application.job_posting?.salary_min || 7000000
  );
  const [probationMonths, setProbationMonths] = useState('3 (Tiga) Bulan');
  const [benefits, setBenefits] = useState(
    'BPJS Kesehatan & Ketenagakerjaan, Tunjangan Transportasi, Laptop Kerja, Cuti Tahunan 12 Hari.'
  );
  const [hrdName, setHrdName] = useState(currentUser?.name || 'Sari Rahma');
  const [hrdTitle, setHrdTitle] = useState('HR & Talent Acquisition Manager');
  const [copied, setCopied] = useState(false);
  const [saving, setSaving] = useState(false);

  const formattedJoinDate = new Date(joinDate).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });

  const formattedExpiryDate = new Date(expiryDate).toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });

  const todayFormatted = new Date().toLocaleDateString('id-ID', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  });

  const emailSubject = `Surat Penawaran Kerja (Offering Letter): ${positionTitle} — ${companyName}`;

  const offeringLetterText = `SURAT PENAWARAN KERJA (JOB OFFER LETTER)
Nomor : OL/${new Date().getFullYear()}/${application.id.toString().padStart(4, '0')}
Tanggal: ${todayFormatted}

Kepada Yth.
Sdr/i ${application.full_name}
Di Tempat

Dengan hormat,

Sehubungan dengan hasil evaluasi dan wawancara kerja yang telah dilakukan, manajemen ${companyName} dengan ini secara resmi menyampaikan penawaran kerja kepada Anda untuk posisi berikut:

1. Posisi / Jabatan  : ${positionTitle}
2. Departemen        : ${department}
3. Tanggal Mulai     : ${formattedJoinDate}
4. Gaji Pokok        : ${formatRupiah(Number(offeredSalary))} per bulan
5. Tunjangan & Fasilitas: ${benefits}
6. Masa Percobaan    : ${probationMonths}

Ketentuan Penerimaan:
Apabila Anda menyetujui penawaran ini, mohon untuk menandatangani surat ini dan mengirimkan kembali konfirmasi sebelum tanggal ${formattedExpiryDate}.

Kami sangat senang menyambut Anda untuk bergabung dan berkembang bersama tim ${companyName}.

Hormat kami,
${companyName}


${hrdName}
${hrdTitle}

--------------------------------------------------
LEMBAR KONFIRMASI KANDIDAT:
Saya, ${application.full_name}, dengan ini MENYATAKAN MENERIMA penawaran kerja di atas.

Tanda Tangan: ____________________
Tanggal     : ____________________`;

  const handlePrint = () => {
    const printWindow = window.open('', '_blank');
    if (!printWindow) {
      showToast('Pop-up browser terblokir. Izinkan pop-up untuk mencetak.', 'error');
      return;
    }
    printWindow.document.write(`
      <!DOCTYPE html>
      <html>
      <head>
        <title>Offering Letter — ${application.full_name}</title>
        <style>
          body { font-family: 'Times New Roman', serif; padding: 40px; color: #111; line-height: 1.6; font-size: 14px; }
          .header { border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 25px; text-align: center; }
          .company-title { font-size: 20px; font-weight: bold; text-transform: uppercase; }
          .letter-title { font-size: 16px; font-weight: bold; margin-top: 15px; text-decoration: underline; }
          table { width: 100%; margin: 15px 0; border-collapse: collapse; }
          table td { padding: 6px 4px; vertical-align: top; }
          .signature-box { margin-top: 50px; display: flex; justify-content: space-between; }
          .sign-col { width: 45%; text-align: center; }
          .sign-line { margin-top: 70px; border-top: 1px solid #333; }
        </style>
      </head>
      <body>
        <div class="header">
          <div class="company-title">${companyName}</div>
          <div style="font-size: 12px; color: #666;">ExpenseFlow Integrated Human Resources Management</div>
          <div class="letter-title">SURAT PENAWARAN KERJA (JOB OFFER LETTER)</div>
          <div style="font-size: 12px; margin-top: 4px;">Ref No: OL/${new Date().getFullYear()}/${application.id.toString().padStart(4, '0')}</div>
        </div>

        <p>Tanggal: <strong>${todayFormatted}</strong></p>
        <p>Kepada Yth.<br><strong>${application.full_name}</strong><br>${application.address || 'Di Tempat'}</p>

        <p>Dengan hormat,</p>
        <p>Berdasarkan rangkaian proses seleksi dan wawancara yang telah Anda ikuti, manajemen <strong>${companyName}</strong> dengan ini dengan bangga menawarkan posisi pekerjaan kepada Anda dengan rincian sebagai berikut:</p>

        <table>
          <tr><td width="200"><strong>Posisi / Jabatan</strong></td><td width="20">:</td><td>${positionTitle}</td></tr>
          <tr><td><strong>Departemen</strong></td><td>:</td><td>${department}</td></tr>
          <tr><td><strong>Tanggal Mulai Bekerja</strong></td><td>:</td><td><strong>${formattedJoinDate}</strong></td></tr>
          <tr><td><strong>Gaji Pokok yang Ditawarkan</strong></td><td>:</td><td><strong>${formatRupiah(Number(offeredSalary))}</strong> / bulan</td></tr>
          <tr><td><strong>Masa Percobaan (Probation)</strong></td><td>:</td><td>${probationMonths}</td></tr>
          <tr><td><strong>Tunjangan &amp; Fasilitas</strong></td><td>:</td><td>${benefits}</td></tr>
        </table>

        <p>Surat penawaran ini berlaku hingga tanggal <strong>${formattedExpiryDate}</strong>. Mohon tanda tangani lembar konfirmasi di bawah ini dan kirimkan kembali kepada kami sebagai tanda persetujuan.</p>

        <div class="signature-box">
          <div class="sign-col">
            <p>Pihak Perusahaan,<br><strong>${companyName}</strong></p>
            <div class="sign-line"></div>
            <strong>${hrdName}</strong><br>${hrdTitle}
          </div>
          <div class="sign-col">
            <p>Pihak Calon Karyawan,<br>Menerima &amp; Menyetujui</p>
            <div class="sign-line"></div>
            <strong>${application.full_name}</strong><br>Tanggal: _______________
          </div>
        </div>
      </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    setTimeout(() => {
      printWindow.print();
    }, 400);
  };

  const handleCopy = () => {
    navigator.clipboard.writeText(offeringLetterText);
    setCopied(true);
    showToast('Teks Offering Letter berhasil disalin!');
    setTimeout(() => setCopied(false), 2500);
  };

  const handleOpenGmail = () => {
    const gmailUrl = `https://mail.google.com/mail/?view=cm&fs=1&to=${encodeURIComponent(
      application.email
    )}&su=${encodeURIComponent(emailSubject)}&body=${encodeURIComponent(offeringLetterText)}`;
    window.open(gmailUrl, '_blank', 'noopener,noreferrer');
  };

  const handleSaveOfferingRecord = async () => {
    setSaving(true);
    const offerRecord = `[OFFERING LETTER DITERBITKAN]: Posisi: ${positionTitle}, Gaji: ${formatRupiah(Number(offeredSalary))}, Join: ${formattedJoinDate}, Batas: ${formattedExpiryDate}.\n${application.notes ? application.notes + '\n\n' : ''}`;
    try {
      await onSaveOffering(offerRecord);
      showToast('Offering Letter berhasil disimpan ke catatan pelamar!');
      onClose();
    } catch (err: any) {
      showToast(err.message || 'Gagal menyimpan data offering.', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-950/70 backdrop-blur-md overflow-y-auto">
      <div className="bg-white dark:bg-slate-900 border border-slate-100 dark:border-slate-800 rounded-3xl max-w-3xl w-full p-6 sm:p-7 space-y-5 shadow-2xl my-8">
        
        {/* Modal Header */}
        <div className="flex items-center justify-between pb-4 border-b border-slate-100 dark:border-slate-800">
          <div className="flex items-center gap-3">
            <div className="p-2.5 rounded-2xl bg-emerald-50 dark:bg-emerald-950/60 border border-emerald-100 dark:border-emerald-800 text-emerald-600 dark:text-emerald-400">
              <FileCheck size={20} />
            </div>
            <div>
              <h3 className="text-base sm:text-lg font-bold text-slate-900 dark:text-white">Generator Surat Penawaran Kerja (Offering Letter)</h3>
              <p className="text-xs text-slate-400">Terbitkan dokumen penawaran resmi untuk {application.full_name}.</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-2 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-400 hover:text-slate-700 dark:hover:text-white transition-colors cursor-pointer"
          >
            <X size={16} />
          </button>
        </div>

        {/* Input Parameters Form */}
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 p-4 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200/80 dark:border-slate-800 text-xs">
          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Posisi Jabatan</label>
            <input
              type="text"
              value={positionTitle}
              onChange={e => setPositionTitle(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
            />
          </div>

          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Departemen</label>
            <input
              type="text"
              value={department}
              onChange={e => setDepartment(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
            />
          </div>

          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Gaji Ditawarkan (IDR)</label>
            <input
              type="number"
              value={offeredSalary}
              onChange={e => setOfferedSalary(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white font-bold focus:outline-none focus:border-indigo-500 text-emerald-600"
            />
          </div>

          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Tanggal Mulai (Join Date)</label>
            <CustomDatePicker
              value={joinDate}
              onChange={val => setJoinDate(val)}
              placeholder="Pilih join date"
              size="sm"
            />
          </div>

          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Masa Percobaan</label>
            <input
              type="text"
              value={probationMonths}
              onChange={e => setProbationMonths(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
            />
          </div>

          <div>
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Batas Tanda Tangan</label>
            <CustomDatePicker
              value={expiryDate}
              onChange={val => setExpiryDate(val)}
              placeholder="Pilih batas ttd"
              size="sm"
            />
          </div>


          <div className="sm:col-span-3">
            <label className="block text-slate-700 dark:text-slate-300 font-semibold mb-1">Tunjangan &amp; Benefit</label>
            <input
              type="text"
              value={benefits}
              onChange={e => setBenefits(e.target.value)}
              className="w-full bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-lg px-3 py-1.5 text-slate-900 dark:text-white focus:outline-none focus:border-indigo-500"
            />
          </div>
        </div>

        {/* Document Live Preview */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <label className="text-xs font-bold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
              <Sparkles size={13} className="text-emerald-600 dark:text-emerald-400" />
              Preview Surat Penawaran Resmi
            </label>
            <div className="flex items-center gap-2">
              <button
                onClick={handleCopy}
                className="text-xs font-semibold text-slate-600 dark:text-slate-300 hover:text-indigo-600 flex items-center gap-1 cursor-pointer"
              >
                {copied ? <Check size={13} className="text-emerald-500" /> : <Copy size={13} />}
                <span>{copied ? 'Tersalin!' : 'Salin'}</span>
              </button>
              <button
                onClick={handlePrint}
                className="text-xs font-semibold text-indigo-600 dark:text-indigo-400 hover:text-indigo-700 flex items-center gap-1 cursor-pointer"
              >
                <Printer size={13} />
                <span>Cetak / PDF</span>
              </button>
            </div>
          </div>
          <div className="p-5 rounded-2xl bg-slate-50 dark:bg-slate-950 border border-slate-200 dark:border-slate-800 text-xs text-slate-700 dark:text-slate-300 font-mono whitespace-pre-wrap max-h-56 overflow-y-auto leading-relaxed border-l-2 border-l-emerald-500">
            {offeringLetterText}
          </div>
        </div>

        {/* Action Buttons */}
        <div className="space-y-2.5 pt-3 border-t border-slate-100 dark:border-slate-800">
          <div className="flex flex-col sm:flex-row items-center gap-2">
            <button
              type="button"
              onClick={handlePrint}
              className="w-full sm:flex-1 py-3 px-5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold text-xs sm:text-sm tracking-wide shadow-md shadow-indigo-500/20 transition-all flex items-center justify-center gap-2 cursor-pointer"
            >
              <Printer size={16} />
              <span>Cetak / Unduh PDF Offering Letter</span>
            </button>

            <button
              type="button"
              onClick={handleOpenGmail}
              className="w-full sm:flex-1 py-3 px-5 rounded-xl bg-gradient-to-r from-red-600 via-rose-600 to-red-600 hover:from-red-500 hover:to-rose-500 text-white font-bold text-xs sm:text-sm tracking-wide shadow-md shadow-red-500/20 transition-all flex items-center justify-center gap-2 cursor-pointer"
            >
              <Mail size={16} />
              <span>Kirim via Gmail Web</span>
              <ExternalLink size={14} />
            </button>
          </div>

          <div className="flex items-center justify-end gap-2 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="py-2 px-4 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-800 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-xs font-semibold cursor-pointer"
            >
              Tutup
            </button>

            <button
              type="button"
              onClick={handleSaveOfferingRecord}
              disabled={saving}
              className="py-2 px-5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold transition-all flex items-center justify-center gap-1.5 shadow-sm cursor-pointer"
            >
              {saving ? <Loader2 size={13} className="animate-spin" /> : <CheckCheck size={14} />}
              <span>Simpan Catatan Penawaran</span>
            </button>
          </div>
        </div>

      </div>
    </div>
  );
}
