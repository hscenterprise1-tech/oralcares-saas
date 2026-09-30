import React, { useState, useRef } from 'react';
import {
  ZoomIn,
  ZoomOut,
  RotateCw,
  RefreshCw,
  Sun,
  Maximize2,
  Minimize2,
  SlidersHorizontal,
  Eye,
  FileImage,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/ovs/primitives/button';
import { Slider } from '@/ovs/primitives/slider';
import { Label } from '@/ovs/primitives/label';
import { Badge } from '@/ovs/primitives/badge';

export interface XRayViewerProps {
  imageUrl: string;
  title?: string;
  capturedDate?: string;
  patientName?: string;
  className?: string;
}

export function XRayViewer({
  imageUrl,
  title = 'Dental Panoramic OPG X-Ray',
  capturedDate = '2026-08-01',
  patientName = 'Patient #P-89201',
  className,
}: XRayViewerProps) {
  const [zoom, setZoom] = useState<number>(1);
  const [rotation, setRotation] = useState<number>(0);
  const [brightness, setBrightness] = useState<number>(100);
  const [contrast, setContrast] = useState<number>(100);
  const [isInverted, setIsInverted] = useState<boolean>(false);
  const [isFullscreen, setIsFullscreen] = useState<boolean>(false);

  // Pan dragging state
  const [pan, setPan] = useState<{ x: number; y: number }>({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState<boolean>(false);
  const dragStartRef = useRef<{ x: number; y: number }>({ x: 0, y: 0 });

  const containerRef = useRef<HTMLDivElement>(null);

  const handleZoomIn = () => setZoom((prev) => Math.min(prev + 0.25, 4));
  const handleZoomOut = () => setZoom((prev) => Math.max(prev - 0.25, 0.5));
  const handleRotate = () => setRotation((prev) => (prev + 90) % 360);

  const handleReset = () => {
    setZoom(1);
    setRotation(0);
    setBrightness(100);
    setContrast(100);
    setIsInverted(false);
    setPan({ x: 0, y: 0 });
  };

  const handleWheel = (e: React.WheelEvent) => {
    e.preventDefault();
    if (e.deltaY < 0) {
      handleZoomIn();
    } else {
      handleZoomOut();
    }
  };

  const handleMouseDown = (e: React.MouseEvent) => {
    if (zoom <= 1) return;
    setIsDragging(true);
    dragStartRef.current = { x: e.clientX - pan.x, y: e.clientY - pan.y };
  };

  const handleMouseMove = (e: React.MouseEvent) => {
    if (!isDragging) return;
    setPan({
      x: e.clientX - dragStartRef.current.x,
      y: e.clientY - dragStartRef.current.y,
    });
  };

  const handleMouseUp = () => setIsDragging(false);

  const toggleFullscreen = () => {
    if (!containerRef.current) return;
    if (!isFullscreen) {
      if (containerRef.current.requestFullscreen) {
        containerRef.current.requestFullscreen();
      }
      setIsFullscreen(true);
    } else {
      if (document.exitFullscreen) {
        document.exitFullscreen();
      }
      setIsFullscreen(false);
    }
  };

  return (
    <div
      ref={containerRef}
      className={cn(
        'w-full bg-slate-950 border border-ovs-border-strong rounded-2xl overflow-hidden shadow-floating flex flex-col',
        isFullscreen && 'fixed inset-0 z-50 rounded-none h-screen border-none',
        className
      )}
    >
      {/* Top Header Controls Bar */}
      <div className="flex flex-wrap items-center justify-between gap-3 p-3 bg-slate-900 border-b border-slate-800 text-white">
        <div className="flex items-center gap-2">
          <FileImage className="w-5 h-5 text-ovs-accent" />
          <div>
            <h3 className="text-xs font-bold text-white font-display leading-tight">{title}</h3>
            <span className="text-[10px] text-slate-400 block font-mono">
              {patientName} · {capturedDate}
            </span>
          </div>
        </div>

        {/* Action Toolbar */}
        <div className="flex items-center gap-1.5 flex-wrap">
          <Button
            size="icon"
            variant="ghost"
            onClick={handleZoomIn}
            className="w-8 h-8 text-slate-300 hover:text-white hover:bg-slate-800"
            title="Zoom In (+)"
          >
            <ZoomIn className="w-4 h-4" />
          </Button>

          <Button
            size="icon"
            variant="ghost"
            onClick={handleZoomOut}
            className="w-8 h-8 text-slate-300 hover:text-white hover:bg-slate-800"
            title="Zoom Out (-)"
          >
            <ZoomOut className="w-4 h-4" />
          </Button>

          <span className="text-[10px] font-mono font-bold text-slate-400 px-1.5 min-w-[36px] text-center">
            {Math.round(zoom * 100)}%
          </span>

          <div className="w-px h-4 bg-slate-800 mx-1" />

          <Button
            size="icon"
            variant="ghost"
            onClick={handleRotate}
            className="w-8 h-8 text-slate-300 hover:text-white hover:bg-slate-800"
            title="Rotate 90°"
          >
            <RotateCw className="w-4 h-4" />
          </Button>

          <Button
            size="icon"
            variant="ghost"
            onClick={() => setIsInverted((prev) => !prev)}
            className={cn(
              'w-8 h-8 text-slate-300 hover:text-white hover:bg-slate-800',
              isInverted && 'bg-ovs-accent/20 text-ovs-accent font-bold'
            )}
            title="Invert Colors (Neg/Pos)"
          >
            <Eye className="w-4 h-4" />
          </Button>

          <Button
            size="icon"
            variant="ghost"
            onClick={handleReset}
            className="w-8 h-8 text-slate-300 hover:text-white hover:bg-slate-800"
            title="Reset Filters"
          >
            <RefreshCw className="w-4 h-4" />
          </Button>

          <div className="w-px h-4 bg-slate-800 mx-1" />

          <Button
            size="icon"
            variant="ghost"
            onClick={toggleFullscreen}
            className="w-8 h-8 text-slate-300 hover:text-white hover:bg-slate-800"
            title="Toggle Fullscreen"
          >
            {isFullscreen ? <Minimize2 className="w-4 h-4" /> : <Maximize2 className="w-4 h-4" />}
          </Button>
        </div>
      </div>

      {/* Main Image Viewport */}
      <div
        onWheel={handleWheel}
        onMouseDown={handleMouseDown}
        onMouseMove={handleMouseMove}
        onMouseUp={handleMouseUp}
        onMouseLeave={handleMouseUp}
        className={cn(
          'relative flex-1 min-h-[380px] bg-black flex items-center justify-center overflow-hidden cursor-grab select-none',
          isDragging && 'cursor-grabbing'
        )}
      >
        {imageUrl ? (
          <img
            src={imageUrl}
            alt={title}
            draggable={false}
            style={{
              transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom}) rotate(${rotation}deg)`,
              filter: `brightness(${brightness}%) contrast(${contrast}%) ${isInverted ? 'invert(100%)' : ''}`,
              transition: isDragging ? 'none' : 'transform 0.15s ease-out, filter 0.15s ease-out',
            }}
            className="max-h-[360px] max-w-full object-contain pointer-events-none drop-shadow-2xl"
          />
        ) : (
          <div className="text-center text-slate-500 space-y-2">
            <FileImage className="w-12 h-12 stroke-1 mx-auto" />
            <p className="text-xs">No X-Ray Image Loaded</p>
          </div>
        )}

        {/* Floating Viewport Status Chip */}
        <div className="absolute top-3 left-3 bg-slate-900/80 backdrop-blur-md px-2.5 py-1 rounded-full border border-slate-800 text-[10px] font-mono text-slate-300 flex items-center gap-2">
          <span>Brightness: {brightness}%</span>
          <span>Contrast: {contrast}%</span>
          {isInverted && <Badge variant="warning" className="text-[9px] py-0 px-1">INVERTED</Badge>}
        </div>
      </div>

      {/* Bottom Brightness / Contrast Sliders Drawer */}
      <div className="p-3 bg-slate-900 border-t border-slate-800 text-white grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Brightness Slider */}
        <div className="flex items-center gap-3">
          <Sun className="w-4 h-4 text-slate-400 flex-shrink-0" />
          <Label className="text-[11px] text-slate-300 min-w-[70px]">Brightness</Label>
          <Slider
            min={20}
            max={200}
            step={5}
            value={[brightness]}
            onValueChange={(val) => setBrightness(val[0])}
            className="flex-1"
          />
          <span className="text-[10px] font-mono text-slate-400 min-w-[32px] text-right">
            {brightness}%
          </span>
        </div>

        {/* Contrast Slider */}
        <div className="flex items-center gap-3">
          <SlidersHorizontal className="w-4 h-4 text-slate-400 flex-shrink-0" />
          <Label className="text-[11px] text-slate-300 min-w-[70px]">Contrast</Label>
          <Slider
            min={20}
            max={200}
            step={5}
            value={[contrast]}
            onValueChange={(val) => setContrast(val[0])}
            className="flex-1"
          />
          <span className="text-[10px] font-mono text-slate-400 min-w-[32px] text-right">
            {contrast}%
          </span>
        </div>
      </div>
    </div>
  );
}
