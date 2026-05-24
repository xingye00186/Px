<template>
  <div id="app" style="left:0px;top:0px;width:400px;height:500px" title="v-for List Test">
    <div style="left:0px;top:0px;width:400px;height:500px" class="main-bg"></div>

    <!-- List header -->
    <span style="left:10px;top:10px;font-size:18px;color:#FFFFFF">{{ listTitle }}</span>

    <!-- Scroll container with v-for list items -->
    <div style="overflow:auto;left:10px;top:50px;width:380px;height:400px" :scroll-top="scrollTop">
      <template v-for="item in todoItems" :key="item.id">
        <div class="item-bg"
             style="height:50px;font-size:14px"
             @click="deleteItem(item.id)">
          <span>{{ item.text }}</span>
        </div>
      </template>
    </div>

    <!-- Add button -->
    <button style="left:150px;top:460px;width:100px;height:30px" class="add-btn" @click="addItem">
      <span style="color:#FFFFFF;font-size:14px;text-align:center">{{ addBtnText }}</span>
    </button>
  </div>
</template>

<script lang="php">
class AppComponent extends ReactiveComponent
{
    public string $listTitle = "Todo List (v6)";
    public string $addBtnText = "Add Item";
    public string $scrollTop = "0";
    public array $todoItems = [
        ['id' => '1', 'text' => 'Task 1'],
        ['id' => '2', 'text' => 'Task 2'],
        ['id' => '3', 'text' => 'Task 3'],
    ];

    public function deleteItem(string $id): void
    {
        foreach ($this->todoItems as $i => $item) {
            if ($item['id'] === $id) {
                unset($this->todoItems[$i]);
                $this->todoItems = array_values($this->todoItems);
                break;
            }
        }
        $this->dirty = true;
    }

    public function addItem(): void
    {
        $newId = (string)(count($this->todoItems) + 1);
        $this->todoItems[] = ['id' => $newId, 'text' => 'Task #' . $newId];
        $this->dirty = true;
    }
}
</script>

<style>
.main-bg { background: #2D2D2D; }
.item-bg { background: #3E3E3E; color: #DDDDDD; }
.add-btn { background: #4488CC; }
</style>
