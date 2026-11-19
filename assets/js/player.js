const data = window.animationData?.body || {};
const frames = Array.isArray(data.frames) ? data.frames : [];
const character = data.character || "";
const frameBase = data.frameBase || "./";
const animationFrame = document.getElementById("animationFrame");
const bufferFrame = document.getElementById("bufferFrame");
const frameCounter = document.getElementById("frameCounter");
const frameDelay = document.getElementById("frameDelay");
const playPauseButton = document.getElementById("playPauseButton");
const progress = document.getElementById("progress");

let currentFrame = 0;
let isPlaying = false;
let timerId;
let frameStartedAt;
let activeImage = animationFrame;
let nextImage = bufferFrame;
let nextFrameReady;
let remainingDelay = frames[0]?.delay ?? 45;

function frameUrl(file) {
  return new URL(file, new URL(frameBase, document.baseURI)).href;
}

function showFrame(index) {
  const frame = frames[index];
  activeImage.alt = `动画帧 ${index + 1}`;
  activeImage.removeAttribute("aria-hidden");
  nextImage.alt = "";
  nextImage.setAttribute("aria-hidden", "true");
  frameCounter.textContent = `帧 ${index + 1} / ${frames.length}`;
  frameDelay.textContent = `${frame.delay} ms / 帧`;
  progress.style.width = `${((index + 1) / frames.length) * 100}%`;
}

function prepareFrame(index, image) {
  image.src = frameUrl(frames[index].file);
  return image.decode().then(
    () => true,
    (error) => {
      console.error(`无法加载动画帧 ${frames[index].file}`, error);
      frameCounter.textContent = `无法加载帧 ${index + 1}`;
      return false;
    },
  );
}

function scheduleNextFrame() {
  if (!isPlaying || frames.length === 0) return;

  frameStartedAt = performance.now();
  timerId = window.setTimeout(async () => {
    const frameReady = await nextFrameReady;
    if (!isPlaying) return;
    if (!frameReady) {
      setPlaying(false);
      playPauseButton.disabled = true;
      return;
    }

    currentFrame = (currentFrame + 1) % frames.length;
    activeImage.classList.remove("active");
    nextImage.classList.add("active");
    [activeImage, nextImage] = [nextImage, activeImage];
    showFrame(currentFrame);
    remainingDelay = frames[currentFrame].delay;
    nextFrameReady = prepareFrame(
      (currentFrame + 1) % frames.length,
      nextImage,
    );
    scheduleNextFrame();
  }, remainingDelay);
}

function setPlaying(playing) {
  if (isPlaying === playing) return;
  isPlaying = playing;
  playPauseButton.textContent = playing ? "暂停" : "播放";
  playPauseButton.setAttribute("aria-pressed", String(playing));

  if (playing) {
    scheduleNextFrame();
  } else {
    window.clearTimeout(timerId);
    remainingDelay = Math.max(
      0,
      remainingDelay - (performance.now() - frameStartedAt),
    );
  }
}

document.addEventListener("visibilitychange", () => {
  if (document.hidden) setPlaying(false);
});

playPauseButton.addEventListener("click", () => setPlaying(!isPlaying));
document.addEventListener("keydown", (event) => {
  if (
    event.code !== "Space" ||
    event.repeat ||
    event.target instanceof HTMLButtonElement
  )
    return;
  event.preventDefault();
  setPlaying(!isPlaying);
});

if (character) {
  document.getElementById("character").textContent = character;
}

if (frames.length > 0) {
  const firstFrameSrc = frameUrl(frames[0].file);
  if (animationFrame.src !== firstFrameSrc) {
    animationFrame.src = firstFrameSrc;
  }
  remainingDelay = frames[currentFrame].delay;
  showFrame(currentFrame);
  playPauseButton.disabled = true;
  Promise.all([
    animationFrame.decode(),
    prepareFrame((currentFrame + 1) % frames.length, bufferFrame),
  ])
    .then(([, nextFrameLoaded]) => {
      if (!nextFrameLoaded) {
        playPauseButton.disabled = true;
        return;
      }
      nextFrameReady = Promise.resolve(true);
      playPauseButton.disabled = false;
      if (!document.hidden) setPlaying(true);
    })
    .catch((error) => {
      console.error("无法初始化动画播放器", error);
      frameCounter.textContent = "无法加载动画";
    });
} else {
  playPauseButton.disabled = true;
  frameCounter.textContent = "没有可播放的帧";
}
