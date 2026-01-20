<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Slider } from '@/components/ui/slider';
import { Button } from '@/components/ui/button';
import { 
    PlayIcon, 
    PauseIcon,
    SpeakerWaveIcon,
    SpeakerXMarkIcon,
    ArrowDownTrayIcon,
} from '@heroicons/vue/24/solid';
import { 
    ArrowPathIcon,
} from '@heroicons/vue/24/outline';

interface Props {
    src: string;
    name?: string;
    fileSize?: number;
    downloadUrl?: string;
}

const props = defineProps<Props>();

const audioRef = ref<HTMLAudioElement | null>(null);
const isPlaying = ref(false);
const isLoading = ref(true);
const hasError = ref(false);
const currentTime = ref(0);
const duration = ref(0);
const volume = ref([100]);
const isMuted = ref(false);
const showVolumeSlider = ref(false);

// Format time as MM:SS
const formatTime = (seconds: number): string => {
    if (isNaN(seconds) || !isFinite(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
};

// Format file size
const formatFileSize = (bytes: number): string => {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
};

const formattedCurrentTime = computed(() => formatTime(currentTime.value));
const formattedDuration = computed(() => formatTime(duration.value));
const progress = computed(() => {
    if (duration.value === 0) return [0];
    return [(currentTime.value / duration.value) * 100];
});

// Playback controls
const togglePlay = async () => {
    if (!audioRef.value || hasError.value) return;
    
    try {
        if (isPlaying.value) {
            audioRef.value.pause();
        } else {
            await audioRef.value.play();
        }
    } catch (err) {
        console.error('Playback error:', err);
        hasError.value = true;
    }
};

const seek = (value: number[] | undefined) => {
    if (!value || !audioRef.value || duration.value === 0) return;
    const newTime = (value[0] / 100) * duration.value;
    audioRef.value.currentTime = newTime;
    currentTime.value = newTime;
};

const setVolume = (value: number[] | undefined) => {
    if (!value || !audioRef.value) return;
    volume.value = value;
    audioRef.value.volume = value[0] / 100;
    isMuted.value = value[0] === 0;
};

const toggleMute = () => {
    if (!audioRef.value) return;
    isMuted.value = !isMuted.value;
    audioRef.value.muted = isMuted.value;
};

const retry = () => {
    if (!audioRef.value) return;
    hasError.value = false;
    isLoading.value = true;
    audioRef.value.load();
};

// Event handlers
const onLoadedMetadata = () => {
    if (!audioRef.value) return;
    duration.value = audioRef.value.duration;
    isLoading.value = false;
};

const onTimeUpdate = () => {
    if (!audioRef.value) return;
    currentTime.value = audioRef.value.currentTime;
};

const onPlay = () => {
    isPlaying.value = true;
};

const onPause = () => {
    isPlaying.value = false;
};

const onEnded = () => {
    isPlaying.value = false;
    currentTime.value = 0;
};

const onError = () => {
    isLoading.value = false;
    hasError.value = true;
};

const onCanPlay = () => {
    isLoading.value = false;
};

// Setup audio element
onMounted(() => {
    if (audioRef.value) {
        audioRef.value.volume = volume.value[0] / 100;
    }
});

// Watch for src changes
watch(() => props.src, () => {
    hasError.value = false;
    isLoading.value = true;
    isPlaying.value = false;
    currentTime.value = 0;
    duration.value = 0;
});
</script>

<template>
    <div class="flex items-center gap-3 rounded-xl border bg-card p-4">
        <!-- Hidden audio element -->
        <audio
            ref="audioRef"
            :src="src"
            preload="metadata"
            @loadedmetadata="onLoadedMetadata"
            @timeupdate="onTimeUpdate"
            @play="onPlay"
            @pause="onPause"
            @ended="onEnded"
            @error="onError"
            @canplay="onCanPlay"
        />

        <!-- Play/Pause Button -->
        <Button
            variant="default"
            size="icon"
            class="h-12 w-12 shrink-0 rounded-full"
            :disabled="isLoading || hasError"
            @click="togglePlay"
        >
            <ArrowPathIcon v-if="isLoading" class="h-5 w-5 animate-spin" />
            <template v-else-if="hasError">
                <ArrowPathIcon class="h-5 w-5" @click.stop="retry" />
            </template>
            <PauseIcon v-else-if="isPlaying" class="h-5 w-5" />
            <PlayIcon v-else class="h-5 w-5" />
        </Button>

        <!-- Progress Section -->
        <div class="flex flex-1 flex-col gap-1">
            <!-- Track name if provided -->
            <div v-if="name" class="flex items-center gap-2">
                <span class="text-sm font-medium truncate">{{ name }}</span>
                <span v-if="fileSize" class="text-xs text-muted-foreground">({{ formatFileSize(fileSize) }})</span>
            </div>
            
            <!-- Progress bar -->
            <div class="flex items-center gap-3">
                <span class="text-xs text-muted-foreground tabular-nums w-10">
                    {{ formattedCurrentTime }}
                </span>
                <Slider
                    :model-value="progress"
                    :max="100"
                    :step="0.1"
                    :disabled="isLoading || hasError || duration === 0"
                    class="flex-1"
                    @update:model-value="seek"
                />
                <span class="text-xs text-muted-foreground tabular-nums w-10 text-right">
                    {{ formattedDuration }}
                </span>
            </div>

            <!-- Error message -->
            <div v-if="hasError" class="text-xs text-destructive">
                Failed to load audio. <button class="underline" @click="retry">Retry</button>
            </div>
        </div>

        <!-- Volume Control -->
        <div 
            class="relative flex items-center"
            @mouseenter="showVolumeSlider = true"
            @mouseleave="showVolumeSlider = false"
        >
            <Button
                variant="ghost"
                size="icon"
                class="h-9 w-9 shrink-0"
                @click="toggleMute"
            >
                <SpeakerXMarkIcon v-if="isMuted || volume[0] === 0" class="h-5 w-5" />
                <SpeakerWaveIcon v-else class="h-5 w-5" />
            </Button>
            
            <!-- Volume slider popup -->
            <div 
                v-show="showVolumeSlider"
                class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 p-3 bg-popover border rounded-lg shadow-lg"
            >
                <Slider
                    :model-value="volume"
                    :max="100"
                    :step="1"
                    orientation="vertical"
                    class="h-24"
                    @update:model-value="setVolume"
                />
            </div>
        </div>

        <!-- Download Button -->
        <a 
            v-if="downloadUrl"
            :href="downloadUrl"
            download
            class="shrink-0"
        >
            <Button variant="ghost" size="icon" class="h-9 w-9">
                <ArrowDownTrayIcon class="h-5 w-5" />
            </Button>
        </a>
    </div>
</template>
