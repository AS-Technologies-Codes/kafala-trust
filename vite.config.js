import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { cpSync } from 'node:fs'
import { resolve } from 'node:path'

let projectRoot
let outputDir

export default defineConfig({
  plugins: [
    vue(),
    {
      name: 'copy-static-assets',
      apply: 'build',
      configResolved(config) {
        projectRoot = config.root
        outputDir = resolve(config.root, config.build.outDir)
      },
      closeBundle() {
        cpSync(
          resolve(projectRoot, 'assets'),
          resolve(outputDir, 'assets'),
          { recursive: true }
        )
      }
    }
  ],
  build: {
    outDir: 'dist',
    emptyOutDir: false
  }
})
