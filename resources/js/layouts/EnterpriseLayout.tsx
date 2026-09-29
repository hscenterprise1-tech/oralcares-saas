import * as React from "react"
import { Sheet, SheetContent } from "@/components/ui/sheet"
import { useKeyboardShortcuts } from "@/hooks/useKeyboardShortcuts"
import { EnterpriseSidebar } from "@/components/enterprise"
import { ExecutiveHeader } from "@/components/executive-shell/ExecutiveHeader"

export function EnterpriseLayout({ 
  children, 
  header, 
  headerActions,
  logoutUrl = "/company/logout",
  quickActions,
  searchContext = 'company'
}: { 
  children: React.ReactNode, 
  header?: React.ReactNode, 
  headerActions?: React.ReactNode,
  logoutUrl?: string,
  quickActions?: React.ReactNode,
  searchContext?: 'company' | 'clinic'
}) {
  useKeyboardShortcuts();
  const [mobileDrawerOpen, setMobileDrawerOpen] = React.useState(false);

  return (
    <div className="flex min-h-screen w-full bg-[#F8FAFC]">
      {/* Fixed Sidebar (Desktop) */}
      <aside className="hidden lg:block w-[260px] shrink-0 h-screen sticky top-0 bg-white border-r border-slate-200/80">
        <EnterpriseSidebar />
      </aside>

      {/* Mobile Drawer */}
      <Sheet open={mobileDrawerOpen} onOpenChange={setMobileDrawerOpen}>
        <SheetContent side="left" className="w-[260px] p-0 bg-white">
          <EnterpriseSidebar />
        </SheetContent>
      </Sheet>

      {/* MAIN CANVAS & HEADER WRAPPER - UNIFIED SCROLL VIEWPORT */}
      <div className="flex-1 flex flex-col min-w-0 h-full overflow-y-auto overflow-x-hidden bg-[#F8FAFC]">
        {/* PERSISTENT STICKY FLOATING CONTROL BAR */}
        <ExecutiveHeader onToggleMobileDrawer={() => setMobileDrawerOpen((o) => !o)} />

        {/* Main Content Area */}
        <main className="flex-1 px-4 lg:px-8 pb-8 pt-3">
          <div className="mx-auto w-full max-w-screen-2xl">
            {header && (
              <div className="mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4 animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div className="flex-1">
                  {header}
                </div>
                {headerActions && (
                  <div className="flex items-center gap-3 shrink-0">
                    {headerActions}
                  </div>
                )}
              </div>
            )}
            <div className="animate-in fade-in slide-in-from-bottom-6 duration-500 ease-out fill-mode-both delay-75">
              {children}
            </div>
          </div>
        </main>
      </div>
    </div>
  )
}
