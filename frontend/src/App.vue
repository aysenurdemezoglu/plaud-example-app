<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'

const email = ref('')
const password = ref('')
const connected = ref(false)
const recordings = ref([])
const selectedRecording = ref(null)
const loading = ref(true)
const submitting = ref(false)
const error = ref('')
const copiedSection = ref('')
const searchQuery = ref('')
const sortOrder = ref('newest')
const dateFrom = ref('')
const dateTo = ref('')
const contentFilter = ref('all')
const audioUrl = ref('')
const audioLoading = ref(false)
const audioError = ref('')
const audioDuration = ref(0)
const audioCurrentTime = ref(0)
const toast = ref(null)
let toastTimer

const isDetail = computed(() => selectedRecording.value !== null)
const transcriptBlocks = computed(() => formatContent(selectedRecording.value?.transcript))
const summaryBlocks = computed(() => formatContent(selectedRecording.value?.summary))

function formatContent(content) {
  if (!content) return []

  const lines = content.replace(/\r\n/g, '\n').split('\n')
  const blocks = []
  let listItems = []

  function flushList() {
    if (listItems.length) {
      blocks.push({ type: 'list', items: listItems })
      listItems = []
    }
  }

  for (const rawLine of lines) {
    const line = rawLine.trim()
    if (!line) {
      flushList()
      continue
    }

    const heading = line.match(/^#{1,6}\s+(.+)$/)
    const bullet = line.match(/^[-*]\s+(.+)$/)
    const numbered = line.match(/^\d+[.)]\s+(.+)$/)

    if (heading) {
      flushList()
      blocks.push({ type: 'heading', text: cleanMarkdown(heading[1]) })
    } else if (bullet || numbered) {
      listItems.push(cleanMarkdown((bullet || numbered)[1]))
    } else {
      flushList()
      blocks.push({ type: 'paragraph', text: cleanMarkdown(line) })
    }
  }

  flushList()
  return blocks
}

function cleanMarkdown(text) {
  return text
    .replace(/\*\*(.+?)\*\*/g, '$1')
    .replace(/__(.+?)__/g, '$1')
    .replace(/`(.+?)`/g, '$1')
    .trim()
}

function formatTime(seconds) {
  if (!Number.isFinite(seconds) || seconds < 0) return '00:00'
  const minutes = Math.floor(seconds / 60)
  const remainder = Math.floor(seconds % 60)
  return `${String(minutes).padStart(2, '0')}:${String(remainder).padStart(2, '0')}`
}

function handleAudioLoaded(event) {
  audioDuration.value = event.target.duration
  audioLoading.value = false
  audioError.value = ''
}

function handleAudioTimeUpdate(event) {
  audioCurrentTime.value = event.target.currentTime
}

function handleAudioError() {
  audioLoading.value = false
  audioError.value = 'The audio could not be played. The temporary URL may have expired.'
  showToast('Audio could not be played.', 'error')
}
const visibleRecordings = computed(() => {
  const terms = searchQuery.value.trim().toLocaleLowerCase().split(/\s+/).filter(Boolean)
  const filtered = recordings.value.filter((recording) => {
    const searchable = `${recording.filename} ${recording.date}`.toLocaleLowerCase()
    const matchesSearch = terms.every((term) => searchable.includes(term))
    const matchesFrom = !dateFrom.value || recording.dateKey >= dateFrom.value
    const matchesTo = !dateTo.value || recording.dateKey <= dateTo.value
    const matchesContent = contentFilter.value === 'all'
      || (contentFilter.value === 'transcript' && recording.hasTranscript)
      || (contentFilter.value === 'summary' && recording.hasSummary)
      || (contentFilter.value === 'missing' && !recording.hasTranscript && !recording.hasSummary)

    return matchesSearch && matchesFrom && matchesTo && matchesContent
  })

  return [...filtered].sort((first, second) => {
    if (sortOrder.value === 'duration') return second.durationMinutes - first.durationMinutes

    const firstDate = Date.parse(first.date.replace(' / ', ' ')) || 0
    const secondDate = Date.parse(second.date.replace(' / ', ' ')) || 0
    return sortOrder.value === 'oldest' ? firstDate - secondDate : secondDate - firstDate
  })
})

function resetFilters() {
  searchQuery.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  contentFilter.value = 'all'
  sortOrder.value = 'newest'
}

async function request(path, options = {}) {
  try {
    const response = await fetch(path, {
      credentials: 'same-origin',
      ...options,
    })
    const data = await response.json().catch(() => ({}))
    if (!response.ok) {
      const requestError = new Error(friendlyError(data.message, response.status))
      requestError.status = response.status
      throw requestError
    }
    return data
  } catch (requestError) {
    if (requestError instanceof TypeError) {
      throw new Error('The server could not be reached. Is the PHP server running?')
    }
    throw requestError
  }
}

function friendlyError(message, status) {
  if (status === 401) return 'Your Plaud session has expired. Please connect again.'
  if (status === 404) return 'This recording or audio is no longer available.'
  if (status === 422) return 'Please enter both your email and password.'
  if (message?.toLowerCase().includes('authentication')) return 'Plaud could not authenticate this account. Check your credentials.'
  if (message?.toLowerCase().includes('audio')) return 'Audio is not available for this recording.'
  return message || 'Something went wrong. Please try again.'
}

function showToast(message, type = 'success') {
  window.clearTimeout(toastTimer)
  toast.value = { message, type }
  toastTimer = window.setTimeout(() => {
    toast.value = null
  }, 3200)
}

async function loadRecordings() {
  loading.value = true
  error.value = ''
  try {
    const data = await request('/api/recordings')
    recordings.value = data.recordings
    connected.value = true
    if (data.recordings.length) showToast(`${data.recordings.length} recordings loaded.`)
  } catch (requestError) {
    connected.value = false
    if (requestError.message !== 'Plaud connection required.') {
      error.value = requestError.message
    }
  } finally {
    loading.value = false
  }
}

async function connect() {
  submitting.value = true
  error.value = ''
  try {
    const body = new URLSearchParams({ email: email.value, password: password.value })
    await request('/api/connect', { method: 'POST', body })
    password.value = ''
    showToast('Plaud account connected.')
    await loadRecordings()
  } catch (requestError) {
    error.value = requestError.message
  } finally {
    submitting.value = false
  }
}

async function openRecording(id) {
  loading.value = true
  error.value = ''
  audioUrl.value = ''
  audioError.value = ''
  audioDuration.value = 0
  audioCurrentTime.value = 0
  audioLoading.value = true
  try {
    const data = await request(`/api/recordings/${encodeURIComponent(id)}`)
    selectedRecording.value = data.recording
    window.history.pushState({ id }, '', `/?id=${encodeURIComponent(id)}`)

    try {
      const audio = await request(`/api/recordings/${encodeURIComponent(id)}/audio-url`)
      audioUrl.value = audio.audioUrl
    } catch (audioRequestError) {
      audioError.value = audioRequestError.message
      showToast(audioError.value, 'error')
    } finally {
      audioLoading.value = false
    }
  } catch (requestError) {
    error.value = requestError.message
    audioLoading.value = false
  } finally {
    loading.value = false
  }
}

function closeDetail() {
  selectedRecording.value = null
  copiedSection.value = ''
  audioUrl.value = ''
  audioError.value = ''
  audioDuration.value = 0
  audioCurrentTime.value = 0
  window.history.pushState({}, '', '/')
}

async function copyText(section, text) {
  if (!text || !navigator.clipboard) return
  await navigator.clipboard.writeText(text)
  copiedSection.value = section
  showToast(`${section === 'summary' ? 'Summary' : 'Transcript'} copied.`)
  window.setTimeout(() => {
    if (copiedSection.value === section) copiedSection.value = ''
  }, 1600)
}

async function disconnect() {
  await request('/api/logout', { method: 'POST' })
  connected.value = false
  recordings.value = []
  selectedRecording.value = null
  audioUrl.value = ''
  audioError.value = ''
  email.value = ''
  error.value = ''
  showToast('You have been disconnected.')
}

function restoreRoute() {
  const id = new URLSearchParams(window.location.search).get('id')
  if (id) openRecording(id)
}

onMounted(async () => {
  window.addEventListener('popstate', () => {
    selectedRecording.value = null
    restoreRoute()
  })
  await loadRecordings()
  restoreRoute()
})

onUnmounted(() => window.clearTimeout(toastTimer))
</script>

<template>
  <main class="shell">
    <header class="topbar">
      <a class="brand" href="/" @click.prevent="closeDetail">
        <span class="brand-mark">P</span>
        <span>Plaud Workspace</span>
      </a>
      <span class="connection-status">
        <span class="status-dot" :class="{ 'is-connected': connected }"></span>
        {{ connected ? 'Connected' : 'Not connected' }}
      </span>
    </header>

    <div v-if="error" class="alert" role="alert">{{ error }}</div>
    <div v-if="toast" class="toast" :class="`toast-${toast.type}`" role="status" aria-live="polite">
      <span class="toast-mark" aria-hidden="true">{{ toast.type === 'error' ? '!' : '✓' }}</span>
      <span>{{ toast.message }}</span>
    </div>

    <section v-if="!connected && loading" class="hero loading-screen" aria-label="Loading workspace">
      <div class="skeleton skeleton-kicker"></div>
      <div class="skeleton skeleton-title"></div>
      <div class="skeleton skeleton-copy"></div>
      <div class="skeleton-connect"><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-line"></div><div class="skeleton skeleton-button"></div></div>
    </section>

    <section v-else-if="!connected" class="hero">
      <p class="eyebrow">Plaud integration workspace</p>
      <h1>Your conversations,<br /><em>ready to revisit.</em></h1>
      <p class="hero-copy">Connect a Plaud account to explore recordings, transcripts, and summaries in one focused workspace.</p>

      <form class="connect-panel" @submit.prevent="connect">
        <div>
          <p class="panel-kicker">Temporary connection</p>
          <h2>Connect your Plaud account</h2>
          <p class="panel-copy">Credentials are sent to the PHP session and never exposed to the Vue interface.</p>
        </div>
        <div class="connect-form">
          <label><span>Email address</span><input v-model="email" type="email" placeholder="you@example.com" autocomplete="email" required /></label>
          <label><span>Password</span><input v-model="password" type="password" placeholder="Plaud password" autocomplete="current-password" required /></label>
          <button type="submit" :disabled="submitting">{{ submitting ? 'Connecting...' : 'Connect account' }} <span aria-hidden="true">&rarr;</span></button>
          <small>OAuth will replace this temporary flow after private-beta access.</small>
        </div>
      </form>
    </section>

    <template v-else>
      <section v-if="isDetail && selectedRecording" class="detail-heading">
        <a class="back-link" href="/" @click.prevent="closeDetail">&larr; All recordings</a>
        <p class="eyebrow">Recording detail</p>
        <h1>{{ selectedRecording.filename }}</h1>
        <p class="detail-meta">{{ selectedRecording.date || 'Undated recording' }} &middot; {{ selectedRecording.durationMinutes }} min</p>
        <div class="detail-stats" aria-label="Recording content summary">
          <span><strong>{{ selectedRecording.transcript ? selectedRecording.transcript.length.toLocaleString() : 0 }}</strong> transcript characters</span>
          <span><strong>{{ selectedRecording.summary ? 'Ready' : 'None' }}</strong> summary</span>
        </div>
        <div class="audio-panel" aria-label="Recording audio">
          <div class="audio-heading"><span class="panel-kicker">Audio</span><span class="content-state">{{ audioLoading ? 'Loading' : audioUrl ? 'Ready' : 'Unavailable' }}</span></div>
          <audio v-if="audioUrl" :src="audioUrl" controls preload="metadata" @loadedmetadata="handleAudioLoaded" @timeupdate="handleAudioTimeUpdate" @error="handleAudioError"></audio>
          <div v-if="audioUrl && !audioError" class="audio-time" aria-live="polite"><span>{{ formatTime(audioCurrentTime) }}</span><span>{{ formatTime(audioDuration) }}</span></div>
          <p v-else-if="audioLoading" class="audio-message">Preparing the audio player...</p>
          <p v-else class="audio-message">{{ audioError || 'Audio is not available for this recording.' }}</p>
          <p v-if="audioUrl && audioError" class="audio-message audio-error">{{ audioError }}</p>
        </div>
      </section>

      <section v-if="isDetail && selectedRecording" class="content-grid" aria-label="Recording content">
        <article class="content-panel">
          <div class="section-heading"><div><span class="panel-kicker">Transcript</span><span class="content-state">{{ selectedRecording.transcript ? 'Available' : 'Not available' }}</span></div><button v-if="selectedRecording.transcript" class="text-button" type="button" @click="copyText('transcript', selectedRecording.transcript)">{{ copiedSection === 'transcript' ? 'Copied' : 'Copy' }}</button></div>
          <div v-if="transcriptBlocks.length" class="rich-text">
            <template v-for="(block, index) in transcriptBlocks" :key="`transcript-${index}`">
              <h3 v-if="block.type === 'heading'">{{ block.text }}</h3>
              <p v-else-if="block.type === 'paragraph'">{{ block.text }}</p>
              <ul v-else><li v-for="item in block.items" :key="item">{{ item }}</li></ul>
            </template>
          </div>
          <p v-else class="content-empty">No transcript is available for this recording.</p>
        </article>
        <article class="content-panel summary-panel">
          <div class="section-heading"><div><span class="panel-kicker">Summary</span><span class="content-state">{{ selectedRecording.summary ? 'Available' : 'Not available' }}</span></div><button v-if="selectedRecording.summary" class="text-button" type="button" @click="copyText('summary', selectedRecording.summary)">{{ copiedSection === 'summary' ? 'Copied' : 'Copy' }}</button></div>
          <div v-if="summaryBlocks.length" class="rich-text">
            <template v-for="(block, index) in summaryBlocks" :key="`summary-${index}`">
              <h3 v-if="block.type === 'heading'">{{ block.text }}</h3>
              <p v-else-if="block.type === 'paragraph'">{{ block.text }}</p>
              <ul v-else><li v-for="item in block.items" :key="item">{{ item }}</li></ul>
            </template>
          </div>
          <p v-else class="content-empty">No summary is available for this recording.</p>
        </article>
      </section>

      <template v-else>
        <section class="workspace-heading">
          <div><p class="eyebrow">Plaud workspace</p><h1>Recordings</h1><p class="hero-copy">A focused view of your recent conversations.</p></div>
          <button class="secondary-button" type="button" @click="disconnect">Disconnect</button>
        </section>
        <section class="recording-tools" aria-label="Recording filters">
          <label class="search-field"><span>Search recordings</span><input v-model="searchQuery" type="search" placeholder="Search by title" /></label>
          <label class="sort-field"><span>Sort by</span><select v-model="sortOrder"><option value="newest">Newest first</option><option value="oldest">Oldest first</option><option value="duration">Longest first</option></select></label>
          <label class="date-field"><span>From</span><input v-model="dateFrom" type="date" /></label>
          <label class="date-field"><span>To</span><input v-model="dateTo" type="date" /></label>
          <label class="content-field"><span>Content</span><select v-model="contentFilter"><option value="all">All recordings</option><option value="transcript">Transcript ready</option><option value="summary">Summary ready</option><option value="missing">Missing both</option></select></label>
          <button class="reset-button" type="button" @click="resetFilters">Reset filters</button>
          <span class="result-count">{{ visibleRecordings.length }} of {{ recordings.length }} recordings</span>
        </section>
        <section class="recording-list" aria-label="Recordings">
          <div v-if="loading" class="skeleton-list" aria-label="Loading recordings">
            <div v-for="index in 4" :key="index" class="skeleton-row"><span class="skeleton skeleton-icon"></span><span class="skeleton-row-text"><span class="skeleton skeleton-name"></span><span class="skeleton skeleton-date"></span></span><span class="skeleton skeleton-duration"></span></div>
          </div>
          <div v-else-if="recordings.length === 0" class="empty-state"><h2>No recordings yet</h2><p>Your active Plaud recordings will appear here.</p></div>
          <div v-else-if="visibleRecordings.length === 0" class="empty-state"><h2>No matching recordings</h2><p>Try a different title or clear the search.</p></div>
          <button v-for="recording in visibleRecordings" v-else :key="recording.id" class="recording-row" type="button" @click="openRecording(recording.id)">
            <span class="recording-index">&#8599;</span><span class="recording-title"><strong>{{ recording.filename }}</strong><small>{{ recording.date || 'Undated recording' }}</small></span><span class="recording-duration">{{ recording.durationMinutes }} min</span><span aria-hidden="true">&rarr;</span>
          </button>
        </section>
      </template>
    </template>
  </main>
</template>
