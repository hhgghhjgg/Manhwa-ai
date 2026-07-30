import * as esbuild from 'esbuild';
import { mkdirSync } from 'fs';

mkdirSync('out', { recursive: true });

// کتابخانه‌هایی که به React وابسته‌ان (react رو external می‌کنیم)
const reactDeps = [
  { name: 'zustand', entry: 'zustand', global: 'Zustand' },
  { name: 'jotai', entry: 'jotai', global: 'Jotai' },
  { name: '@reduxjs/toolkit', entry: '@reduxjs/toolkit', global: 'RTK' },
  { name: 'react-redux', entry: 'react-redux', global: 'ReactRedux' },
  { name: '@tanstack/react-query', entry: '@tanstack/react-query', global: 'ReactQuery' },
  { name: 'swr', entry: 'swr', global: 'SWR' },
  { name: 'react-hook-form', entry: 'react-hook-form', global: 'ReactHookForm' },
  { name: 'framer-motion', entry: 'framer-motion', global: 'Motion' },
];

// کتابخانه‌های مستقل (بدون وابستگی به React)
const standalone = [
  { name: 'zod', entry: 'zod', global: 'Zod' },
  { name: 'yup', entry: 'yup', global: 'Yup' },
  { name: 'axios', entry: 'axios', global: 'axios' },
  { name: 'dayjs', entry: 'dayjs', global: 'dayjs' },
  { name: 'date-fns', entry: 'date-fns', global: 'dateFns' },
  { name: 'clsx', entry: 'clsx', global: 'clsx' },
  { name: 'tailwind-merge', entry: 'tailwind-merge', global: 'twMerge' },
  { name: '@supabase/supabase-js', entry: '@supabase/supabase-js', global: 'supabase' },
  { name: 'pocketbase', entry: 'pocketbase', global: 'PocketBase' },
  { name: 'lucide', entry: 'lucide', global: 'lucide' },
];

async function build(lib, externalReact = false) {
  try {
    const outfile = `out/${lib.name.replace(/[@\/]/g, '_')}.umd.js`;
    
    await esbuild.build({
      stdin: {
        contents: `export * as lib from '${lib.entry}'; export { default } from '${lib.entry}';`,
        resolveDir: '.',
      },
      bundle: true,
      format: 'iife',
      globalName: lib.global,
      outfile,
      minify: true,
      sourcemap: false,
      platform: 'browser',
      target: ['es2020'],
      external: externalReact ? ['react', 'react-dom', 'react/jsx-runtime'] : [],
      define: {
        'process.env.NODE_ENV': '"production"',
      },
      logLevel: 'silent',
    });
    
    console.log(`✅ ${lib.name} → ${outfile}`);
    return true;
  } catch (err) {
    console.log(`❌ ${lib.name} FAILED: ${err.message.split('\n')[0]}`);
    return false;
  }
}

// 1. اول React و ReactDOM رو جدا bundle کن
console.log('\n=== Building React Core ===');
try {
  await esbuild.build({
    stdin: {
      contents: `
        import React from 'react';
        import ReactDOM from 'react-dom/client';
        import ReactDOMServer from 'react-dom/server';
        window.React = React;
        window.ReactDOM = ReactDOM;
        window.ReactDOMServer = ReactDOMServer;
      `,
      resolveDir: '.',
    },
    bundle: true,
    format: 'iife',
    outfile: 'out/react-dom.umd.js',
    minify: true,
    platform: 'browser',
    target: ['es2020'],
    define: { 'process.env.NODE_ENV': '"production"' },
    logLevel: 'silent',
  });
  console.log('✅ react + react-dom → out/react-dom.umd.js');
} catch (err) {
  console.log(`❌ React FAILED: ${err.message}`);
}

// 2. کتابخانه‌های مستقل
console.log('\n=== Building Standalone Libraries ===');
for (const lib of standalone) {
  await build(lib, false);
}

// 3. کتابخانه‌های وابسته به React
console.log('\n=== Building React-Dependent Libraries ===');
for (const lib of reactDeps) {
  await build(lib, true);
}

console.log('\n🎉 ALL DONE!');
